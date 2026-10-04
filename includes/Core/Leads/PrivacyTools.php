<?php
/**
 * WordPress privacy tools (export and erase personal data, suggested policy text).
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Core\Leads;

use Krivo\ChatFlow\Core\Flow\FlowRepository;
use Krivo\ChatFlow\Infrastructure\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * Plugs the leads into Tools > Export Personal Data and Tools > Erase Personal Data, which work by
 * e-mail address, and suggests privacy-policy text for the site owner to review.
 */
final class PrivacyTools {

	public const PER_PAGE = 50;

	/**
	 * Registers the hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'erasers' ) );
		add_action( 'admin_init', array( __CLASS__, 'policy_text' ) );
	}

	/**
	 * Adds the exporter.
	 *
	 * @param array $exporters Registered exporters.
	 * @return array
	 */
	public static function exporters( array $exporters ): array {
		$exporters['krivo-chatflow'] = array(
			'exporter_friendly_name' => __( 'Krivo ChatFlow leads', 'krivo-chatflow' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $exporters;
	}

	/**
	 * Adds the eraser.
	 *
	 * @param array $erasers Registered erasers.
	 * @return array
	 */
	public static function erasers( array $erasers ): array {
		$erasers['krivo-chatflow'] = array(
			'eraser_friendly_name' => __( 'Krivo ChatFlow leads', 'krivo-chatflow' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Exports the leads of an e-mail address, one page at a time.
	 *
	 * @param string $email E-mail address.
	 * @param int    $page  Page, starting at 1.
	 * @return array{data:array[],done:bool}
	 */
	public static function export( string $email, int $page = 1 ): array {
		$page   = max( 1, $page );
		$leads  = LeadRepository::by_email( $email, self::PER_PAGE, ( $page - 1 ) * self::PER_PAGE );
		$titles = array();
		foreach ( FlowRepository::all() as $flow ) {
			$titles[ $flow['id'] ] = $flow['title'];
		}

		$data = array();
		foreach ( $leads as $lead ) {
			$item = array(
				array( 'name' => __( 'Chat', 'krivo-chatflow' ), 'value' => $titles[ $lead['flow_id'] ] ?? (string) $lead['flow_id'] ),
				array( 'name' => __( 'Date', 'krivo-chatflow' ), 'value' => Clock::iso( (string) $lead['created_at'] ) ),
				array( 'name' => __( 'Name', 'krivo-chatflow' ), 'value' => (string) $lead['name'] ),
				array( 'name' => __( 'E-mail', 'krivo-chatflow' ), 'value' => (string) $lead['email'] ),
				array( 'name' => __( 'Phone', 'krivo-chatflow' ), 'value' => (string) $lead['phone'] ),
				array( 'name' => __( 'Status', 'krivo-chatflow' ), 'value' => (string) $lead['status'] ),
				array( 'name' => __( 'Page', 'krivo-chatflow' ), 'value' => (string) $lead['page_url'] ),
				array( 'name' => __( 'Referrer', 'krivo-chatflow' ), 'value' => (string) $lead['referrer'] ),
				array( 'name' => __( 'Campaign', 'krivo-chatflow' ), 'value' => trim( $lead['utm_source'] . ' ' . $lead['utm_medium'] . ' ' . $lead['utm_campaign'] ) ),
			);
			foreach ( (array) $lead['answers'] as $answer ) {
				if ( is_array( $answer ) ) {
					$item[] = array(
						'name'  => (string) ( $answer['label'] ?? '' ),
						'value' => (string) ( $answer['display'] ?? '' ),
					);
				}
			}
			foreach ( (array) $lead['consent'] as $consent ) {
				if ( is_array( $consent ) && ! empty( $consent['accepted'] ) ) {
					$item[] = array(
						'name'  => __( 'Consent given', 'krivo-chatflow' ),
						'value' => (string) ( $consent['at'] ?? '' ),
					);
				}
			}
			$data[] = array(
				'group_id'          => 'krivo-chatflow-leads',
				'group_label'       => __( 'Krivo ChatFlow leads', 'krivo-chatflow' ),
				'group_description' => __( 'Messages sent through the chat widget of this site.', 'krivo-chatflow' ),
				'item_id'           => 'krivo-chatflow-lead-' . $lead['id'],
				'data'              => $item,
			);
		}
		return array(
			'data' => $data,
			'done' => count( $leads ) < self::PER_PAGE,
		);
	}

	/**
	 * Erases the leads of an e-mail address (and their automation log).
	 *
	 * Deleting shifts the result set, so every call reads the first page again.
	 *
	 * @param string $email E-mail address.
	 * @param int    $page  Page (unused: see above).
	 * @return array{items_removed:int,items_retained:bool,messages:string[],done:bool}
	 */
	public static function erase( string $email, int $page = 1 ): array {
		unset( $page );
		$leads   = LeadRepository::by_email( $email, self::PER_PAGE, 0 );
		$removed = LeadRepository::delete( array_column( $leads, 'id' ) );
		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => count( $leads ) < self::PER_PAGE,
		);
	}

	/**
	 * Suggests privacy-policy text (Settings > Privacy > Policy Guide).
	 *
	 * @return void
	 */
	public static function policy_text(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		wp_add_privacy_policy_content( 'Krivo ChatFlow', wp_kses_post( wpautop( self::policy_html() ) ) );
	}

	/**
	 * The suggested text.
	 *
	 * @return string
	 */
	public static function policy_html(): string {
		$parts   = array();
		$parts[] = '<h3>' . esc_html__( 'Chat widget (Krivo ChatFlow)', 'krivo-chatflow' ) . '</h3>';
		$parts[] = esc_html__( 'This site has a chat widget that asks visitors a few questions. Suggested text, to be reviewed and adapted to how you use it:', 'krivo-chatflow' );
		$parts[] = '<strong>' . esc_html__( 'What we collect', 'krivo-chatflow' ) . '</strong> ' . esc_html__( 'The answers a visitor chooses to send (for example name, e-mail, phone and message), the page they were on, the referring page and campaign parameters (UTM), the kind of device (desktop, tablet or mobile) and, when a consent box is shown, the consent given and when.', 'krivo-chatflow' );
		$parts[] = '<strong>' . esc_html__( 'Where it is stored', 'krivo-chatflow' ) . '</strong> ' . esc_html__( 'In this site\'s own database. The plugin does not send visitor data to any service unless the site owner turns on an e-mail notification to their own address or a webhook to a service they chose. If a webhook is on, the data of each new lead is sent to that service.', 'krivo-chatflow' );
		$parts[] = '<strong>' . esc_html__( 'WhatsApp', 'krivo-chatflow' ) . '</strong> ' . esc_html__( 'When the visitor chooses to continue on WhatsApp, their browser opens a wa.me link with their message already written. The visitor sends it from WhatsApp; this site does not contact WhatsApp. WhatsApp\'s own privacy policy applies from that moment.', 'krivo-chatflow' );
		$parts[] = '<strong>' . esc_html__( 'Cookies and browser storage', 'krivo-chatflow' ) . '</strong> ' . esc_html__( 'The widget sets no cookies. It uses the browser\'s session storage (to remember within the visit that the chat was opened and the first campaign parameters) and local storage (to remember that the greeting bubble was dismissed). Nothing identifies the visitor.', 'krivo-chatflow' );
		$parts[] = '<strong>' . esc_html__( 'Retention and rights', 'krivo-chatflow' ) . '</strong> ' . esc_html__( 'Leads are kept until the site owner deletes them or the retention period set in the plugin ends. A visitor can ask for their data to be exported or erased; the site owner can do so with the WordPress tools under Tools > Export Personal Data and Erase Personal Data, which include these leads.', 'krivo-chatflow' );
		return implode( "\n\n", $parts );
	}
}
