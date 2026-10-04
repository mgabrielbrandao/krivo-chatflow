<?php
/**
 * Lead storage.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Core\Leads;

use Krivo\ChatFlow\Infrastructure\Db\Tables;
use Krivo\ChatFlow\Infrastructure\Support\Clock;
use Krivo\ChatFlow\Infrastructure\Support\Json;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes leads in the custom table. Every query is prepared; column names and ordering
 * come from allowlists, never from request data.
 */
final class LeadRepository {

	public const STATUSES = array( 'new', 'contacted', 'converted', 'lost', 'spam' );

	private const ORDERABLE = array(
		'created_at' => 'created_at',
		'name'       => 'name',
		'email'      => 'email',
		'status'     => 'status',
		'id'         => 'id',
	);

	/**
	 * Inserts a lead.
	 *
	 * @param array $data Lead data (see keys used below).
	 * @return int New lead id, 0 on failure.
	 */
	public static function insert( array $data ): int {
		global $wpdb;

		$now = Clock::now();
		$row = array(
			'public_id'         => wp_generate_uuid4(),
			'flow_id'           => (int) ( $data['flow_id'] ?? 0 ),
			'flow_revision'     => (int) ( $data['flow_revision'] ?? 0 ),
			'status'            => in_array( $data['status'] ?? 'new', self::STATUSES, true ) ? $data['status'] : 'new',
			'name'              => (string) ( $data['name'] ?? '' ),
			'email'             => (string) ( $data['email'] ?? '' ),
			'phone'             => (string) ( $data['phone'] ?? '' ),
			'destination_key'   => (string) ( $data['destination_key'] ?? '' ),
			'destination_label' => (string) ( $data['destination_label'] ?? '' ),
			'channel'           => 'whatsapp' === ( $data['channel'] ?? '' ) ? 'whatsapp' : 'message',
			'source'            => in_array( $data['source'] ?? 'widget', array( 'widget', 'shortcode' ), true ) ? $data['source'] : 'widget',
			'in_hours'          => isset( $data['in_hours'] ) && null !== $data['in_hours'] ? (int) (bool) $data['in_hours'] : null,
			'device'            => (string) ( $data['device'] ?? '' ),
			'answers'           => Json::encode( (array) ( $data['answers'] ?? array() ) ),
			'consent'           => array() === (array) ( $data['consent'] ?? array() ) ? null : Json::encode( $data['consent'] ),
			'page_url'          => (string) ( $data['page_url'] ?? '' ),
			'page_title'        => (string) ( $data['page_title'] ?? '' ),
			'referrer'          => (string) ( $data['referrer'] ?? '' ),
			'utm_source'        => (string) ( $data['utm_source'] ?? '' ),
			'utm_medium'        => (string) ( $data['utm_medium'] ?? '' ),
			'utm_campaign'      => (string) ( $data['utm_campaign'] ?? '' ),
			'utm_content'       => (string) ( $data['utm_content'] ?? '' ),
			'utm_term'          => (string) ( $data['utm_term'] ?? '' ),
			'created_at'        => $now,
			'updated_at'        => $now,
		);

		$formats = array( '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' );
		$ok      = $wpdb->insert( Tables::leads(), $row, $formats ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Finds a lead by id.
	 *
	 * @param int $id Lead id.
	 * @return array|null
	 */
	public static function get( int $id ): ?array {
		global $wpdb;
		$table = Tables::leads();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * Finds a lead by its public UUID.
	 *
	 * @param string $public_id UUID.
	 * @return array|null
	 */
	public static function get_by_public_id( string $public_id ): ?array {
		global $wpdb;
		$table = Tables::leads();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE public_id = %s", $public_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * Searches leads.
	 *
	 * @param array $args search, status, flow_id, from (Y-m-d), to (Y-m-d), orderby, order, page, per_page.
	 * @return array{items:array[],total:int}
	 */
	public static function query( array $args = array() ): array {
		global $wpdb;
		$table = Tables::leads();

		list( $where, $values ) = self::where( $args );

		$orderby  = self::ORDERABLE[ $args['orderby'] ?? 'created_at' ] ?? 'created_at';
		$order    = 'ASC' === strtoupper( (string) ( $args['order'] ?? 'DESC' ) ) ? 'ASC' : 'DESC';
		$per_page = max( 1, min( 100, (int) ( $args['per_page'] ?? 20 ) ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$offset   = ( $page - 1 ) * $per_page;

		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where}", $values ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared

		$sql_values = array_merge( $values, array( $per_page, $offset ) );
		$rows       = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY {$orderby} {$order}, id DESC LIMIT %d OFFSET %d", $sql_values ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);

		return array(
			'items' => array_map( array( __CLASS__, 'hydrate' ), (array) $rows ),
			'total' => $total,
		);
	}

	/**
	 * Streams leads in batches (used by the CSV export so memory stays flat).
	 *
	 * @param array    $args     Same filters as query().
	 * @param callable $callback Receives each hydrated lead.
	 * @param int      $batch    Batch size.
	 * @return void
	 */
	public static function each( array $args, callable $callback, int $batch = 500 ): void {
		global $wpdb;
		$table = Tables::leads();
		list( $where, $values ) = self::where( $args );
		$last_id = PHP_INT_MAX;
		do {
			$sql  = $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} AND id < %d ORDER BY id DESC LIMIT %d", array_merge( $values, array( $last_id, $batch ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
			foreach ( (array) $rows as $row ) {
				$last_id = (int) $row['id'];
				call_user_func( $callback, self::hydrate( $row ) );
			}
		} while ( is_array( $rows ) && count( $rows ) === $batch );
	}

	/**
	 * Changes the status of a lead.
	 *
	 * @param int    $id     Lead id.
	 * @param string $status New status.
	 * @return array{from:string,to:string}|null Null when the lead does not exist or the status is invalid.
	 */
	public static function update_status( int $id, string $status ): ?array {
		global $wpdb;
		$lead = self::get( $id );
		if ( null === $lead || ! in_array( $status, self::STATUSES, true ) ) {
			return null;
		}
		if ( $lead['status'] !== $status ) {
			$wpdb->update( Tables::leads(), array( 'status' => $status, 'updated_at' => Clock::now() ), array( 'id' => $id ), array( '%s', '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		}
		return array(
			'from' => $lead['status'],
			'to'   => $status,
		);
	}

	/**
	 * Updates the destination of a lead (re-routing).
	 *
	 * @param int    $id    Lead id.
	 * @param string $key   Destination key.
	 * @param string $label Destination label.
	 * @return void
	 */
	public static function update_destination( int $id, string $key, string $label ): void {
		global $wpdb;
		$wpdb->update( Tables::leads(), array( 'destination_key' => $key, 'destination_label' => $label, 'updated_at' => Clock::now() ), array( 'id' => $id ), array( '%s', '%s', '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Deletes leads and their automation runs.
	 *
	 * @param int[] $ids Lead ids.
	 * @return int Number of leads deleted.
	 */
	public static function delete( array $ids ): int {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
		if ( array() === $ids ) {
			return 0;
		}
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$runs         = Tables::runs();
		$leads        = Tables::leads();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$runs} WHERE lead_id IN ({$placeholders})", $ids ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$leads} WHERE id IN ({$placeholders})", $ids ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		return (int) $deleted;
	}

	/**
	 * Deletes leads older than a number of days (retention), in a bounded batch.
	 *
	 * @param int $days  Age in days.
	 * @param int $limit Maximum leads to delete in this call.
	 * @return int Deleted count.
	 */
	public static function purge_older_than( int $days, int $limit = 500 ): int {
		global $wpdb;
		if ( $days <= 0 ) {
			return 0;
		}
		$table = Tables::leads();
		$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE created_at < %s ORDER BY id ASC LIMIT %d", Clock::now( -$days * DAY_IN_SECONDS ), $limit ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return self::delete( array_map( 'intval', (array) $ids ) );
	}

	/**
	 * Number of leads per status.
	 *
	 * @param int $flow_id Flow id, 0 for all.
	 * @return array<string,int>
	 */
	public static function status_counts( int $flow_id = 0 ): array {
		global $wpdb;
		$table  = Tables::leads();
		$counts = array_fill_keys( self::STATUSES, 0 );
		if ( $flow_id > 0 ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT status, COUNT(*) AS total FROM {$table} WHERE flow_id = %d GROUP BY status", $flow_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} else {
			$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$table} GROUP BY status", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		foreach ( (array) $rows as $row ) {
			if ( isset( $counts[ $row['status'] ] ) ) {
				$counts[ $row['status'] ] = (int) $row['total'];
			}
		}
		return $counts;
	}

	/**
	 * Leads with a given e-mail address (WordPress privacy export/erase tools work by e-mail).
	 *
	 * @param string $email  E-mail address.
	 * @param int    $limit  Page size.
	 * @param int    $offset Offset.
	 * @return array[]
	 */
	public static function by_email( string $email, int $limit = 50, int $offset = 0 ): array {
		global $wpdb;
		$table = Tables::leads();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE email = %s ORDER BY id ASC LIMIT %d OFFSET %d", strtolower( $email ), $limit, $offset ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( array( __CLASS__, 'hydrate' ), (array) $rows );
	}

	/**
	 * Builds the WHERE clause and its values from the filters.
	 *
	 * @param array $args Filters.
	 * @return array{0:string,1:array}
	 */
	private static function where( array $args ): array {
		global $wpdb;
		$clauses = array( '1=1' );
		$values  = array();

		if ( ! empty( $args['status'] ) && in_array( $args['status'], self::STATUSES, true ) ) {
			$clauses[] = 'status = %s';
			$values[]  = $args['status'];
		} elseif ( isset( $args['status_not'] ) && in_array( $args['status_not'], self::STATUSES, true ) ) {
			$clauses[] = 'status <> %s';
			$values[]  = $args['status_not'];
		}
		if ( ! empty( $args['flow_id'] ) ) {
			$clauses[] = 'flow_id = %d';
			$values[]  = (int) $args['flow_id'];
		}
		if ( ! empty( $args['search'] ) ) {
			$like      = '%' . $wpdb->esc_like( trim( (string) $args['search'] ) ) . '%';
			$clauses[] = '(name LIKE %s OR email LIKE %s OR phone LIKE %s)';
			array_push( $values, $like, $like, $like );
		}
		if ( ! empty( $args['from'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $args['from'] ) ) {
			$clauses[] = 'created_at >= %s';
			$values[]  = get_gmt_from_date( $args['from'] . ' 00:00:00' );
		}
		if ( ! empty( $args['to'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $args['to'] ) ) {
			$clauses[] = 'created_at <= %s';
			$values[]  = get_gmt_from_date( $args['to'] . ' 23:59:59' );
		}

		return array( implode( ' AND ', $clauses ), $values );
	}

	/**
	 * Casts a database row to its public shape.
	 *
	 * @param array $row Raw row.
	 * @return array
	 */
	private static function hydrate( array $row ): array {
		foreach ( array( 'id', 'flow_id', 'flow_revision' ) as $key ) {
			$row[ $key ] = (int) $row[ $key ];
		}
		$row['in_hours'] = null === $row['in_hours'] ? null : (bool) (int) $row['in_hours'];
		$row['answers']  = Json::decode( (string) $row['answers'] );
		$row['consent']  = Json::decode( (string) ( $row['consent'] ?? '' ) );
		return $row;
	}
}
