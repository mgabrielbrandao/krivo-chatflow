<?php
/**
 * Admin menu.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Admin;

use Krivo\ChatFlow\Infrastructure\Caps\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * One top-level menu (the plugin has seven areas) placed at the end of the list, with no promotional
 * links, no "upgrade" entries and no badges. Every submenu has its own capability, so a user who may
 * only read leads sees only "Leads".
 */
final class Menu {

	public const TOP = 'krivo-chatflow';

	/**
	 * Hook suffixes of the registered pages (used to load assets only on our screens).
	 *
	 * @var string[]
	 */
	private static $hooks = array();

	/**
	 * Registers the hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_pages' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( KRIVO_CHATFLOW_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * The areas of the app: screen => [ page slug, label, capability ].
	 *
	 * @return array<string,array{0:string,1:string,2:string}>
	 */
	public static function screens(): array {
		$any = Capabilities::first_granted();
		return array(
			'dashboard'    => array( self::TOP, __( 'Dashboard', 'krivo-chatflow' ), $any ),
			'flows'        => array( self::TOP . '-flows', __( 'ChatFlows', 'krivo-chatflow' ), Capabilities::EDIT_FLOWS ),
			'leads'        => array( self::TOP . '-leads', __( 'Leads', 'krivo-chatflow' ), Capabilities::VIEW_LEADS ),
			'routing'      => array( self::TOP . '-routing', __( 'Routing', 'krivo-chatflow' ), Capabilities::MANAGE_ROUTING ),
			'integrations' => array( self::TOP . '-integrations', __( 'Integrations', 'krivo-chatflow' ), Capabilities::VIEW_INTEGRATIONS ),
			'analytics'    => array( self::TOP . '-analytics', __( 'Analytics', 'krivo-chatflow' ), Capabilities::VIEW_ANALYTICS ),
			'settings'     => array( self::TOP . '-settings', __( 'Settings', 'krivo-chatflow' ), current_user_can( Capabilities::MANAGE_SETTINGS ) ? Capabilities::MANAGE_SETTINGS : Capabilities::MANAGE_EMAIL ),
		);
	}

	/**
	 * Adds the pages the current user may open.
	 *
	 * @return void
	 */
	public static function add_pages(): void {
		$any = Capabilities::first_granted();
		if ( '' === $any ) {
			return;
		}

		$allowed = array();
		foreach ( self::screens() as $screen => $def ) {
			if ( '' !== $def[2] && current_user_can( $def[2] ) ) {
				$allowed[ $screen ] = $def;
			}
		}
		if ( array() === $allowed ) {
			return;
		}

		// null position: the end of the menu, never an aggressive slot. The landing page (Dashboard)
		// shares the top-level slug, so WordPress does not list it twice.
		self::$hooks[] = (string) add_menu_page(
			__( 'Krivo ChatFlow', 'krivo-chatflow' ),
			__( 'Krivo ChatFlow', 'krivo-chatflow' ),
			$any,
			self::TOP,
			array( Screen::class, 'render' ),
			self::icon(),
			null
		);

		foreach ( $allowed as $def ) {
			self::$hooks[] = (string) add_submenu_page(
				self::TOP,
				/* translators: %s: screen name. */
				sprintf( __( '%s ‹ Krivo ChatFlow', 'krivo-chatflow' ), $def[1] ),
				$def[1],
				$def[2],
				$def[0],
				array( Screen::class, 'render' )
			);
		}
	}

	/**
	 * Whether a hook suffix belongs to one of our pages.
	 *
	 * @param string $hook Hook suffix from admin_enqueue_scripts.
	 * @return bool
	 */
	public static function owns( string $hook ): bool {
		return in_array( $hook, self::$hooks, true );
	}

	/**
	 * Screen name for a page slug.
	 *
	 * @param string $page The "page" query argument.
	 * @return string
	 */
	public static function screen_for( string $page ): string {
		foreach ( self::screens() as $screen => $def ) {
			if ( $def[0] === $page ) {
				return $screen;
			}
		}
		return 'dashboard';
	}

	/**
	 * URL of a screen.
	 *
	 * @param string $screen Screen name.
	 * @return string
	 */
	public static function url( string $screen ): string {
		$def = self::screens()[ $screen ] ?? self::screens()['dashboard'];
		return admin_url( 'admin.php?page=' . $def[0] );
	}

	/**
	 * Settings link on the Plugins screen.
	 *
	 * @param string[] $links Existing links.
	 * @return string[]
	 */
	public static function action_links( array $links ): array {
		if ( current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			array_unshift( $links, '<a href="' . esc_url( self::url( 'settings' ) ) . '">' . esc_html__( 'Settings', 'krivo-chatflow' ) . '</a>' );
		}
		return $links;
	}

	/**
	 * Monochrome menu icon as a data URI (WordPress recolours it for hover and the current item).
	 *
	 * @return string
	 */
	private static function icon(): string {
		$file = KRIVO_CHATFLOW_DIR . 'assets/images/menu-icon.svg';
		if ( ! is_readable( $file ) ) {
			return 'dashicons-format-chat';
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Reads the plugin's own static icon; admin menus require a base64 data URI to recolour SVG icons (the documented WordPress way).
		return 'data:image/svg+xml;base64,' . base64_encode( (string) file_get_contents( $file ) );
	}
}
