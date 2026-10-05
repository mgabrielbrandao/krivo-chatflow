<?php
/**
 * Flow storage.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Core\Flow;

use Krivo\ChatFlow\Core\Stats\StatsRepository;
use Krivo\ChatFlow\Infrastructure\Db\Tables;
use Krivo\ChatFlow\Infrastructure\Support\Clock;
use Krivo\ChatFlow\Infrastructure\Support\Json;

defined( 'ABSPATH' ) || exit;

/**
 * Flows with a draft and a published version. Editing never affects the live widget until the
 * administrator publishes.
 */
final class FlowRepository {

	public const DRAFT     = 'draft';
	public const PUBLISHED = 'published';
	public const PAUSED    = 'paused';

	/**
	 * Creates a flow (as a draft).
	 *
	 * @param array $config  Raw configuration.
	 * @param int   $user_id Author.
	 * @return int New flow id, 0 on failure.
	 */
	public static function create( array $config, int $user_id ): int {
		global $wpdb;
		$clean = Sanitizer::sanitize( $config );
		$now   = Clock::now();
		$ok    = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			Tables::flows(),
			array(
				'title'          => $clean['name'],
				'status'         => self::DRAFT,
				'schema_version' => Defaults::SCHEMA,
				'revision'       => 0,
				'config_draft'   => Json::encode( $clean ),
				'created_by'     => $user_id,
				'created_at'     => $now,
				'updated_at'     => $now,
			),
			array( '%s', '%s', '%d', '%d', '%s', '%d', '%s', '%s' )
		);
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Loads a flow with both versions.
	 *
	 * @param int $id Flow id.
	 * @return array|null
	 */
	public static function get( int $id ): ?array {
		global $wpdb;
		$table = Tables::flows();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * The published configuration, or null when the flow is not published.
	 *
	 * @param int $id Flow id.
	 * @return array|null { id, revision, config }
	 */
	public static function published( int $id ): ?array {
		$flow = self::get( $id );
		if ( null === $flow || self::PUBLISHED !== $flow['status'] || null === $flow['published'] ) {
			return null;
		}
		return array(
			'id'       => $flow['id'],
			'revision' => $flow['revision'],
			'config'   => $flow['published'],
		);
	}

	/**
	 * Lists flows without their configuration.
	 *
	 * @return array[]
	 */
	public static function all(): array {
		global $wpdb;
		$table = Tables::flows();
		$rows  = $wpdb->get_results( "SELECT id, title, status, revision, updated_at, published_at, created_at FROM {$table} ORDER BY updated_at DESC, id DESC", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map(
			static function ( $row ) {
				$row['id']       = (int) $row['id'];
				$row['revision'] = (int) $row['revision'];
				return $row;
			},
			(array) $rows
		);
	}

	/**
	 * Saves the draft. The caller may pass the hash of the draft it started from; if the stored draft
	 * changed meanwhile (another administrator), nothing is overwritten.
	 *
	 * @param int         $id        Flow id.
	 * @param array       $config    Raw configuration.
	 * @param string|null $base_hash Hash the editor started from (null skips the check).
	 * @return array|\WP_Error The flow, or a conflict / not-found error.
	 */
	public static function save_draft( int $id, array $config, ?string $base_hash = null ) {
		global $wpdb;
		$flow = self::get( $id );
		if ( null === $flow ) {
			return new \WP_Error( 'krivo_chatflow_not_found', __( 'Flow not found.', 'krivo-chatflow' ), array( 'status' => 404 ) );
		}
		if ( null !== $base_hash && '' !== $base_hash && $base_hash !== $flow['draft_hash'] ) {
			return new \WP_Error( 'krivo_chatflow_conflict', __( 'Someone else edited this flow. Reload the page to get the latest version.', 'krivo-chatflow' ), array( 'status' => 409 ) );
		}
		$clean = Sanitizer::sanitize( $config );
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			Tables::flows(),
			array(
				'title'        => $clean['name'],
				'config_draft' => Json::encode( $clean ),
				'updated_at'   => Clock::now(),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
		return (array) self::get( $id );
	}

	/**
	 * Publishes the draft. Blocks when the validation report has errors.
	 *
	 * @param int $id Flow id.
	 * @return array|\WP_Error { flow, report } or an error carrying the report.
	 */
	public static function publish( int $id ) {
		global $wpdb;
		$flow = self::get( $id );
		if ( null === $flow ) {
			return new \WP_Error( 'krivo_chatflow_not_found', __( 'Flow not found.', 'krivo-chatflow' ), array( 'status' => 404 ) );
		}
		$config = Sanitizer::sanitize( $flow['draft'] );
		$report = Validator::report( $config );
		if ( array() !== $report['errors'] ) {
			return new \WP_Error(
				'krivo_chatflow_validation',
				__( 'The flow has problems that must be fixed before publishing.', 'krivo-chatflow' ),
				array(
					'status' => 422,
					'report' => $report,
				)
			);
		}

		$now = Clock::now();
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			Tables::flows(),
			array(
				'title'            => $config['name'],
				'status'           => self::PUBLISHED,
				'config_draft'     => Json::encode( $config ),
				'config_published' => Json::encode( $config ),
				'revision'         => $flow['revision'] + 1,
				'published_at'     => $now,
				'updated_at'       => $now,
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s' ),
			array( '%d' )
		);
		Index::rebuild();

		$published = (array) self::get( $id );
		/**
		 * Fires after a flow was published (cache plugins can purge here).
		 *
		 * @param int   $id     Flow id.
		 * @param array $config Published configuration.
		 */
		do_action( 'krivo_chatflow_flow_published', $id, $config );

		return array(
			'flow'   => $published,
			'report' => $report,
		);
	}

	/**
	 * Pauses a published flow (the widget disappears, the published version is kept).
	 *
	 * @param int $id Flow id.
	 * @return bool
	 */
	public static function pause( int $id ): bool {
		global $wpdb;
		$flow = self::get( $id );
		if ( null === $flow || self::PUBLISHED !== $flow['status'] ) {
			return false;
		}
		$wpdb->update(
			Tables::flows(),
			array(
				'status'     => self::PAUSED,
				'updated_at' => Clock::now(),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		Index::rebuild();
		return true;
	}

	/**
	 * Deletes a flow and its counters. Leads are kept (they are the owner's data).
	 *
	 * @param int $id Flow id.
	 * @return bool
	 */
	public static function delete( int $id ): bool {
		global $wpdb;
		if ( null === self::get( $id ) ) {
			return false;
		}
		$wpdb->delete( Tables::flows(), array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		StatsRepository::delete_for_flow( $id );
		Index::rebuild();
		return true;
	}

	/**
	 * Duplicates a flow as a new draft. Connections (and their secrets) are referenced, not copied.
	 *
	 * @param int $id      Flow id.
	 * @param int $user_id Author of the copy.
	 * @return int New flow id, 0 on failure.
	 */
	public static function duplicate( int $id, int $user_id ): int {
		$flow = self::get( $id );
		if ( null === $flow ) {
			return 0;
		}
		$config = $flow['draft'];
		/* translators: %s: flow name. */
		$config['name'] = sprintf( __( '%s (copy)', 'krivo-chatflow' ), $config['name'] );
		// Fresh ids avoid two flows sharing ids in logs and logic.
		$json = Json::encode( $config );
		return self::create( Json::decode( $json ), $user_id );
	}

	/**
	 * Casts a row and decodes both configurations.
	 *
	 * @param array $row Raw row.
	 * @return array
	 */
	private static function hydrate( array $row ): array {
		$draft     = Json::decode( (string) $row['config_draft'] );
		$published = null === $row['config_published'] ? null : Json::decode( (string) $row['config_published'] );
		return array(
			'id'           => (int) $row['id'],
			'title'        => (string) $row['title'],
			'status'       => (string) $row['status'],
			'revision'     => (int) $row['revision'],
			'draft'        => Sanitizer::sanitize( $draft ),
			'draft_hash'   => md5( (string) $row['config_draft'] ),
			'published'    => null === $published || array() === $published ? null : $published,
			'created_by'   => (int) $row['created_by'],
			'created_at'   => (string) $row['created_at'],
			'updated_at'   => (string) $row['updated_at'],
			'published_at' => null === $row['published_at'] ? '' : (string) $row['published_at'],
		);
	}
}
