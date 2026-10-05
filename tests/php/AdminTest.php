<?php
/**
 * Tests for the admin shell: menu, assets, notices, activation redirect and the export link.
 *
 * @package Krivo\ChatFlow
 */

use Krivo\ChatFlow\Admin\Activation;
use Krivo\ChatFlow\Admin\Assets;
use Krivo\ChatFlow\Admin\Menu;
use Krivo\ChatFlow\Admin\Notices;
use Krivo\ChatFlow\Admin\Screen;
use Krivo\ChatFlow\Core\Flow\FlowRepository;
use Krivo\ChatFlow\Core\Leads\CsvExporter;
use Krivo\ChatFlow\Core\Settings\Settings;
use Krivo\ChatFlow\Infrastructure\Caps\Capabilities;
use Krivo\ChatFlow\Infrastructure\Db\Installer;

/**
 * @group admin
 */
class AdminTest extends Krivo_TestCase {

	public function set_up(): void {
		parent::set_up();
		Capabilities::grant_default();
		unset( $GLOBALS['submenu'], $GLOBALS['menu'], $GLOBALS['_registered_pages'], $GLOBALS['admin_page_hooks'] );
		$_GET = array();
	}

	public function tear_down(): void {
		$_GET = array();
		set_current_screen( 'front' );
		wp_set_current_user( 0 );
		foreach ( array( Assets::HANDLE, 'krivo-chatflow-preview-panel' ) as $handle ) {
			wp_dequeue_script( $handle );
			wp_deregister_script( $handle );
			wp_dequeue_style( $handle );
			wp_deregister_style( $handle );
		}
		parent::tear_down();
	}

	private function user_with( array $caps, string $role = 'subscriber' ): int {
		$id   = self::factory()->user->create( array( 'role' => $role ) );
		$user = new WP_User( $id );
		foreach ( $caps as $cap ) {
			$user->add_cap( $cap );
		}
		wp_set_current_user( $id );
		return $id;
	}

	private function admin(): int {
		$id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $id );
		return $id;
	}

	// ------------------------------------------------------------------ menu

	public function test_an_administrator_gets_one_top_level_menu_with_every_screen(): void {
		$this->admin();
		set_current_screen( 'dashboard' );
		Menu::add_pages();

		global $submenu;
		$slugs = array_column( $submenu[ Menu::TOP ], 2 );
		$this->assertSame(
			array( 'krivo-chatflow', 'krivo-chatflow-flows', 'krivo-chatflow-leads', 'krivo-chatflow-routing', 'krivo-chatflow-integrations', 'krivo-chatflow-analytics', 'krivo-chatflow-settings' ),
			$slugs
		);
	}

	public function test_the_menu_does_not_claim_a_fixed_position_so_it_never_displaces_another_menu(): void {
		$this->admin();
		global $menu;
		$menu = array(
			2  => array( 'Dashboard', 'read', 'index.php', '', '', '', '' ),
			26 => array( 'Comments', 'read', 'edit-comments.php', '', '', '', '' ),
			99 => array( '', 'read', 'separator-last', '', 'wp-menu-separator' ),
		);
		Menu::add_pages();
		$found = null;
		foreach ( $menu as $position => $item ) {
			if ( Menu::TOP === $item[2] ) {
				$found = (float) $position;
			}
		}
		$this->assertNotNull( $found, 'the menu exists' );
		$this->assertGreaterThan( 99, $found, 'appended after the core menus instead of taking a popular position' );
	}

	public function test_each_person_sees_only_the_screens_they_can_use(): void {
		$this->user_with( array( Capabilities::VIEW_LEADS ) );
		Menu::add_pages();
		global $submenu;
		$this->assertSame( array( 'krivo-chatflow', 'krivo-chatflow-leads' ), array_column( $submenu[ Menu::TOP ], 2 ) );
	}

	public function test_people_without_any_capability_get_no_menu_at_all(): void {
		$this->user_with( array(), 'editor' );
		Menu::add_pages();
		global $menu;
		$tops = array_column( $menu ?? array(), 2 );
		$this->assertNotContains( Menu::TOP, $tops );
	}

	public function test_a_person_who_only_manages_e_mail_can_still_open_settings(): void {
		$this->user_with( array( Capabilities::MANAGE_EMAIL ) );
		$this->assertSame( Capabilities::MANAGE_EMAIL, Menu::screens()['settings'][2] );
		$this->user_with( array( Capabilities::MANAGE_SETTINGS, Capabilities::MANAGE_EMAIL ) );
		$this->assertSame( Capabilities::MANAGE_SETTINGS, Menu::screens()['settings'][2] );
	}

	public function test_screen_for_maps_a_page_slug_and_falls_back_to_the_dashboard(): void {
		$this->assertSame( 'leads', Menu::screen_for( 'krivo-chatflow-leads' ) );
		$this->assertSame( 'dashboard', Menu::screen_for( 'krivo-chatflow' ) );
		$this->assertSame( 'dashboard', Menu::screen_for( 'something-else' ) );
	}

	public function test_the_menu_icon_is_an_inline_svg_so_no_image_request_is_needed(): void {
		$this->admin();
		Menu::add_pages();
		global $menu;
		foreach ( $menu as $item ) {
			if ( Menu::TOP === $item[2] ) {
				$this->assertStringStartsWith( 'data:image/svg+xml;base64,', $item[6] );
				$svg = base64_decode( substr( $item[6], strlen( 'data:image/svg+xml;base64,' ) ), true );
				$this->assertStringContainsString( '<svg', (string) $svg );
				$this->assertStringNotContainsString( '<script', (string) $svg );
				return;
			}
		}
		$this->fail( 'menu not found' );
	}

	public function test_the_plugins_screen_gets_a_settings_link_only_for_people_who_can_use_it(): void {
		$this->admin();
		$links = Menu::action_links( array( '<a href="#">Deactivate</a>' ) );
		$this->assertCount( 2, $links );
		$this->assertStringContainsString( 'krivo-chatflow-settings', $links[0] );
		$this->user_with( array() );
		$this->assertCount( 1, Menu::action_links( array( '<a href="#">Deactivate</a>' ) ) );
	}

	// ------------------------------------------------------------------ assets

	public function test_assets_load_only_on_the_plugins_own_screens(): void {
		$this->admin();
		Menu::add_pages();
		$hook = get_plugin_page_hookname( 'krivo-chatflow-leads', Menu::TOP );

		Assets::enqueue( 'edit.php' );
		$this->assertFalse( wp_script_is( Assets::HANDLE, 'enqueued' ), 'not on the posts screen' );
		Assets::enqueue( 'toplevel_page_some-other-plugin' );
		$this->assertFalse( wp_script_is( Assets::HANDLE, 'enqueued' ), 'not on another plugin\'s screen' );

		$_GET['page'] = 'krivo-chatflow-leads';
		Assets::enqueue( $hook );
		$this->assertTrue( wp_script_is( Assets::HANDLE, 'enqueued' ) );
		$this->assertTrue( wp_style_is( Assets::HANDLE, 'enqueued' ) );
	}

	public function test_the_admin_app_uses_the_react_that_ships_with_wordpress(): void {
		$this->admin();
		Menu::add_pages();
		$_GET['page'] = 'krivo-chatflow-leads';
		Assets::enqueue( get_plugin_page_hookname( 'krivo-chatflow-leads', Menu::TOP ) );
		$deps = wp_scripts()->registered[ Assets::HANDLE ]->deps;
		foreach ( array( 'wp-element', 'wp-i18n', 'wp-api-fetch' ) as $dependency ) {
			$this->assertContains( $dependency, $deps );
		}
		$this->assertNotContains( 'react', $deps, 'No second copy of React' );
	}

	public function test_the_editor_loads_the_visitor_panel_for_the_live_preview_and_the_media_library(): void {
		$this->admin();
		Menu::add_pages();
		$_GET['page'] = 'krivo-chatflow-flows';
		Assets::enqueue( get_plugin_page_hookname( 'krivo-chatflow-flows', Menu::TOP ) );
		$this->assertContains( 'krivo-chatflow-preview-panel', wp_scripts()->registered[ Assets::HANDLE ]->deps );
		$this->assertTrue( wp_style_is( 'krivo-chatflow-preview-panel', 'enqueued' ) );

		wp_dequeue_script( Assets::HANDLE );
		wp_deregister_script( Assets::HANDLE );
		$_GET['page'] = 'krivo-chatflow-leads';
		Assets::enqueue( get_plugin_page_hookname( 'krivo-chatflow-leads', Menu::TOP ) );
		$this->assertNotContains( 'krivo-chatflow-preview-panel', wp_scripts()->registered[ Assets::HANDLE ]->deps, 'only the editor and the dashboard need the preview' );
	}

	public function test_every_enqueued_file_exists_in_the_plugin(): void {
		foreach ( array( 'assets/admin/admin.js', 'assets/admin/admin.css', 'assets/frontend/panel.js', 'assets/frontend/panel.css', 'assets/frontend/bubble.css', 'assets/frontend/loader.js' ) as $file ) {
			$this->assertFileExists( KRIVO_CHATFLOW_DIR . $file, $file . ' must be built and shipped' );
		}
	}

	public function test_the_data_handed_to_the_app_has_no_secrets(): void {
		Settings::update( array( 'branding' => array( 'signature_enabled' => true ) ) );
		update_option( 'krivo_chatflow_email', array( 'mode' => 'custom', 'host' => 'smtp.example.test', 'username' => 'smtp-user' ) );
		$this->admin();
		$json = wp_json_encode( Assets::data( 'dashboard' ) );

		foreach ( array( 'smtp.example.test', 'smtp-user', 'password', 'secret', 'api_key', 'token', 'webhook' ) as $needle ) {
			$this->assertStringNotContainsStringIgnoringCase( $needle, $json, $needle . ' must not be in the page source' );
		}
		$data = Assets::data( 'leads' );
		$this->assertSame( 'leads', $data['screen'] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{10}$/', $data['nonce'] );
		$this->assertSame( rest_url( 'krivo-chatflow/v1/' ), $data['rest'] );
		$this->assertTrue( $data['caps'][ Capabilities::EDIT_FLOWS ] );
	}

	public function test_the_app_only_knows_the_capabilities_of_the_current_user(): void {
		$this->user_with( array( Capabilities::VIEW_LEADS ) );
		$data = Assets::data( 'leads' );
		$this->assertTrue( $data['caps'][ Capabilities::VIEW_LEADS ] );
		$this->assertFalse( $data['caps'][ Capabilities::MANAGE_SETTINGS ] );
		$this->assertSame( array( 'dashboard', 'leads' ), array_column( $data['menu'], 'screen' ) );
	}

	// ------------------------------------------------------------------ host page

	public function test_the_host_page_prints_a_mount_point_after_the_notices_marker(): void {
		$this->admin();
		$_GET['page'] = 'krivo-chatflow-routing';
		ob_start();
		Screen::render();
		$html = ob_get_clean();
		$this->assertLessThan( strpos( $html, 'id="krivo-chatflow-admin"' ), strpos( $html, 'wp-header-end' ), 'WordPress and other plugins print their notices above the app' );
		$this->assertStringContainsString( 'data-screen="routing"', $html );
		$this->assertStringContainsString( '<noscript>', $html );
		$this->assertStringContainsString( 'screen-reader-text', $html, 'the page has an h1 for screen readers' );
	}

	// ------------------------------------------------------------------ notices

	public function test_the_welcome_notice_shows_once_on_the_plugins_screen_until_dismissed_or_a_chat_exists(): void {
		$user = $this->admin();
		set_current_screen( 'plugins' );

		ob_start();
		Notices::welcome();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'Krivo ChatFlow is ready', $html );
		$this->assertStringContainsString( '_wpnonce=', $html );

		set_current_screen( 'dashboard' );
		ob_start();
		Notices::welcome();
		$this->assertSame( '', ob_get_clean(), 'only on the plugins screen' );

		set_current_screen( 'plugins' );
		update_user_meta( $user, Notices::META, array( 'welcome' ) );
		ob_start();
		Notices::welcome();
		$this->assertSame( '', ob_get_clean(), 'not after it was dismissed' );

		delete_user_meta( $user, Notices::META );
		FlowRepository::create( array( 'name' => 'Existing' ), $user );
		ob_start();
		Notices::welcome();
		$this->assertSame( '', ob_get_clean(), 'not once a chat exists' );
	}

	public function test_the_welcome_notice_is_not_shown_to_people_who_cannot_create_chats(): void {
		$this->user_with( array( Capabilities::VIEW_LEADS ) );
		set_current_screen( 'plugins' );
		ob_start();
		Notices::welcome();
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_dismissing_requires_the_nonce_and_a_capability(): void {
		$this->admin();
		$_GET['notice'] = 'welcome';
		$this->expectException( WPDieException::class );
		Notices::dismiss(); // No nonce.
	}

	public function test_a_dismissal_is_remembered_per_user_and_unknown_keys_are_ignored(): void {
		$this->admin();
		Notices::remember( 'something-else' );
		$this->assertFalse( Notices::dismissed( 'something-else' ) );
		Notices::remember( 'welcome' );
		Notices::remember( 'welcome' );
		$this->assertTrue( Notices::dismissed( 'welcome' ) );
		$this->assertSame( array( 'welcome' ), get_user_meta( get_current_user_id(), Notices::META, true ), 'stored once' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertFalse( Notices::dismissed( 'welcome' ), 'another user still sees it' );
	}

	// ------------------------------------------------------------------ activation redirect

	public function test_activation_redirect_happens_once_and_only_for_people_with_access(): void {
		$this->user_with( array() );
		set_transient( Installer::ACTIVATION_TRANSIENT, 1, 30 );
		Activation::maybe_redirect();
		$this->assertFalse( get_transient( Installer::ACTIVATION_TRANSIENT ), 'the flag is consumed even when nobody is redirected' );
	}

	public function test_activation_redirect_is_skipped_for_bulk_activation(): void {
		$this->admin();
		set_transient( Installer::ACTIVATION_TRANSIENT, 1, 30 );
		$_GET['activate-multi'] = 'true';
		Activation::maybe_redirect(); // Would exit() if it redirected.
		$this->assertFalse( get_transient( Installer::ACTIVATION_TRANSIENT ) );
		unset( $_GET['activate-multi'] );
	}

	public function test_nothing_happens_when_the_flag_is_not_set(): void {
		$this->admin();
		delete_transient( Installer::ACTIVATION_TRANSIENT );
		Activation::maybe_redirect();
		$this->assertTrue( true, 'no exit, no redirect' );
	}

	// ------------------------------------------------------------------ export link

	public function test_the_export_address_can_be_used_as_a_link_without_further_escaping(): void {
		$this->admin();
		$url = CsvExporter::url();
		$this->assertStringNotContainsString( '&#038;', $url );
		$this->assertStringNotContainsString( '&amp;', $url );
		$parts = wp_parse_url( $url );
		parse_str( (string) $parts['query'], $query );
		$this->assertSame( CsvExporter::ACTION, $query['action'] );
		$this->assertSame( 1, wp_verify_nonce( $query['_wpnonce'], CsvExporter::ACTION ) );
	}
}
