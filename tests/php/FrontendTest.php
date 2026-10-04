<?php
/**
 * Tests for what the visitor's page receives: where the widget appears, what is enqueued, what is printed.
 *
 * @package Krivo\ChatFlow
 */

use Krivo\ChatFlow\Core\Flow\FlowRepository;
use Krivo\ChatFlow\Core\Flow\Index;
use Krivo\ChatFlow\Core\Settings\Settings;
use Krivo\ChatFlow\Frontend\Assets;
use Krivo\ChatFlow\Frontend\Gate;
use Krivo\ChatFlow\Frontend\Renderer;
use Krivo\ChatFlow\Frontend\Shortcode;
use Krivo\ChatFlow\Infrastructure\Caps\Capabilities;

/**
 * @group frontend
 */
class FrontendTest extends Krivo_TestCase {

	use Krivo_FlowFactory;

	public function set_up(): void {
		parent::set_up();
		Gate::reset();
		Assets::reset();
		wp_dequeue_style( Assets::HANDLE );
		wp_dequeue_script( Assets::HANDLE );
		wp_deregister_style( Assets::HANDLE );
		wp_deregister_script( Assets::HANDLE );
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		Gate::reset();
		Assets::reset();
		parent::tear_down();
	}

	/**
	 * Publishes a flow with the given display settings.
	 *
	 * @param array $display Display overrides.
	 * @param array $over    Other config overrides.
	 * @return int
	 */
	private function published( array $display = array(), array $over = array() ): int {
		return $this->flow( null, array_merge( array( 'display' => $display ), $over ) );
	}

	private function visit( string $path = '/' ): void {
		Gate::reset();
		$this->go_to( home_url( $path ) );
	}

	private function footer(): string {
		ob_start();
		Assets::print_footer();
		return (string) ob_get_clean();
	}

	// ------------------------------------------------------------------ gate

	public function test_nothing_happens_when_no_flow_is_published(): void {
		$this->visit();
		$this->assertNull( Gate::select() );
		Assets::maybe_enqueue();
		$this->assertFalse( wp_script_is( Assets::HANDLE, 'enqueued' ) );
		$this->assertSame( '', $this->footer(), 'A page without a widget gets no markup at all' );
	}

	public function test_a_site_wide_flow_appears_everywhere(): void {
		$id = $this->published();
		foreach ( array( '/', '/?p=1', '/?s=hello', '/anything/' ) as $path ) {
			$this->visit( $path );
			$this->assertSame( $id, Gate::select()['id'], $path );
		}
	}

	public function test_exclusion_rules_hide_the_widget_on_matching_urls(): void {
		$this->published( array( 'mode' => 'all', 'exclude' => array( array( 'type' => 'url', 'op' => 'contains', 'value' => '/checkout/' ) ) ) );
		$this->visit( '/shop/' );
		$this->assertNotNull( Gate::select() );
		$this->visit( '/checkout/' );
		$this->assertNull( Gate::select() );
		$this->visit( '/checkout/?step=2' );
		$this->assertNull( Gate::select() );
	}

	public function test_only_mode_needs_a_matching_include_rule(): void {
		$this->published( array( 'mode' => 'only', 'include' => array( array( 'type' => 'url', 'op' => 'starts_with', 'value' => '/servicos' ) ) ) );
		$this->visit( '/servicos/site/' );
		$this->assertNotNull( Gate::select() );
		$this->visit( '/contato/' );
		$this->assertNull( Gate::select() );
	}

	public function test_url_rule_operators(): void {
		$cases = array(
			array( 'equals', '/sobre/', '/sobre/', true ),
			array( 'equals', '/sobre', '/sobre/', true ),
			array( 'equals', '/sobre/', '/sobre/equipe/', false ),
			array( 'ends_with', '/contato/', '/pt/contato/', true ),
			array( 'matches', '/blog/*/comentarios', '/blog/post-1/comentarios', true ),
			array( 'matches', '/blog/*/comentarios', '/blog/post-1/', false ),
			array( 'contains', 'PROMO', '/black-promo/', true ),
			array( 'contains', 'utm_source=ad', '/?utm_source=ads', true ),
		);
		foreach ( $cases as $i => list( $op, $pattern, $path, $expected ) ) {
			$this->truncate();
			Index::rebuild();
			delete_option( 'krivo_chatflow_index' );
			$this->published( array( 'mode' => 'only', 'include' => array( array( 'type' => 'url', 'op' => $op, 'value' => $pattern ) ) ) );
			$this->visit( $path );
			$this->assertSame( $expected, null !== Gate::select(), "#$i $op $pattern on $path" );
		}
	}

	public function test_page_type_rules(): void {
		$this->published( array( 'mode' => 'only', 'include' => array( array( 'type' => 'page_type', 'value' => 'front_page' ) ) ) );
		$this->visit( '/' );
		$this->assertNotNull( Gate::select() );
		$page = self::factory()->post->create( array( 'post_type' => 'page', 'post_title' => 'About' ) );
		$this->visit( '/?page_id=' . $page );
		$this->assertNull( Gate::select() );
	}

	public function test_post_post_type_and_term_rules(): void {
		$post = self::factory()->post->create( array( 'post_title' => 'Hello' ) );
		$term = self::factory()->term->create( array( 'taxonomy' => 'category', 'name' => 'Promos', 'slug' => 'promos' ) );
		wp_set_post_categories( $post, array( $term ) );
		$other = self::factory()->post->create( array( 'post_title' => 'Other' ) );

		$rules = array(
			array( 'post', array( 'type' => 'post', 'value' => $post ) ),
			array( 'post_type', array( 'type' => 'post_type', 'value' => 'post' ) ),
			array( 'term', array( 'type' => 'term', 'taxonomy' => 'category', 'value' => 'promos' ) ),
		);
		foreach ( $rules as list( $label, $rule ) ) {
			$this->truncate();
			delete_option( 'krivo_chatflow_index' );
			$this->published( array( 'mode' => 'only', 'include' => array( $rule ) ) );
			$this->visit( '/?p=' . $post );
			$this->assertNotNull( Gate::select(), "$label matches its post" );
			$this->visit( '/?p=' . $other );
			$this->assertSame( 'post_type' === $label, null !== Gate::select(), "$label on another post" );
		}
	}

	public function test_logged_in_and_logged_out_rules(): void {
		$this->published( array( 'logged' => 'out' ) );
		$this->visit();
		$this->assertNotNull( Gate::select() );
		wp_set_current_user( self::factory()->user->create() );
		$this->visit();
		$this->assertNull( Gate::select() );

		$this->truncate();
		delete_option( 'krivo_chatflow_index' );
		$this->published( array( 'logged' => 'in' ) );
		$this->visit();
		$this->assertNotNull( Gate::select() );
		wp_set_current_user( 0 );
		$this->visit();
		$this->assertNull( Gate::select() );
	}

	public function test_manual_flows_never_appear_by_themselves(): void {
		$this->published( array( 'mode' => 'none' ) );
		$this->visit();
		$this->assertNull( Gate::select() );
	}

	public function test_the_highest_priority_flow_wins_and_only_one_bubble_appears(): void {
		$low  = $this->published( array( 'priority' => 5 ) );
		$high = $this->published( array( 'priority' => 50 ) );
		$this->visit();
		$this->assertSame( $high, Gate::select()['id'] );
		$this->assertNotSame( $low, Gate::select()['id'] );
	}

	public function test_paused_flows_disappear(): void {
		$id = $this->published();
		FlowRepository::pause( $id );
		$this->visit();
		$this->assertNull( Gate::select() );
	}

	public function test_developers_can_veto_a_page(): void {
		$this->published();
		add_filter( 'krivo_chatflow_should_render', '__return_false' );
		$this->visit();
		$this->assertNull( Gate::select() );
	}

	public function test_it_never_runs_in_admin_feeds_embeds_or_ajax(): void {
		$this->published();
		$this->visit( '/?feed=rss2' );
		$this->assertNull( Gate::select(), 'feed' );
		add_filter( 'wp_doing_ajax', '__return_true' );
		$this->visit();
		$this->assertNull( Gate::select(), 'ajax' );
		remove_filter( 'wp_doing_ajax', '__return_true' );
		$this->visit();
		set_current_screen( 'dashboard' ); // go_to() resets the screen, so it is set afterwards.
		Gate::reset();
		$this->assertNull( Gate::select(), 'admin' );
		set_current_screen( 'front' );
	}

	// ------------------------------------------------------------------ draft preview

	public function test_a_draft_preview_needs_a_valid_nonce_and_the_capability(): void {
		Capabilities::grant_default();
		$draft = FlowRepository::create( array( 'name' => 'Draft' ), 1 );

		$editor = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $editor );
		$url = add_query_arg( array( 'krivo_chatflow_preview' => $draft, '_wpnonce' => wp_create_nonce( 'krivo_chatflow_preview_' . $draft ) ), home_url( '/' ) );
		Gate::reset();
		$this->go_to( $url );
		$selected = Gate::select();
		$this->assertSame( $draft, $selected['id'] );
		$this->assertTrue( $selected['preview'] );

		$bad = add_query_arg( array( 'krivo_chatflow_preview' => $draft, '_wpnonce' => 'forged' ), home_url( '/' ) );
		Gate::reset();
		$this->go_to( $bad );
		$this->assertNull( Gate::select(), 'Wrong nonce' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$nonce = wp_create_nonce( 'krivo_chatflow_preview_' . $draft );
		Gate::reset();
		$this->go_to( add_query_arg( array( 'krivo_chatflow_preview' => $draft, '_wpnonce' => $nonce ), home_url( '/' ) ) );
		$this->assertNull( Gate::select(), 'Without the capability even a valid nonce is not enough' );

		wp_set_current_user( 0 );
		Gate::reset();
		$this->go_to( $url );
		$this->assertNull( Gate::select(), 'Visitors never see drafts' );
	}

	// ------------------------------------------------------------------ assets and markup

	public function test_a_matching_page_enqueues_two_small_files_with_defer_and_prints_the_bubble(): void {
		$id = $this->published();
		$this->visit();
		Assets::maybe_enqueue();

		$this->assertTrue( wp_style_is( Assets::HANDLE, 'enqueued' ) );
		$this->assertTrue( wp_script_is( Assets::HANDLE, 'enqueued' ) );
		$script = wp_scripts()->registered[ Assets::HANDLE ];
		$this->assertSame( 'defer', $script->extra['strategy'] );
		$this->assertSame( 1, $script->extra['group'], 'In the footer' );
		$this->assertStringEndsWith( 'assets/frontend/loader.js', $script->src );
		$this->assertStringEndsWith( 'assets/frontend/bubble.css', wp_styles()->registered[ Assets::HANDLE ]->src );
		$this->assertArrayNotHasKey( 'krivo-chatflow-panel', wp_scripts()->registered, 'The panel is downloaded on intent, never enqueued' );

		$html = $this->footer();
		$this->assertStringContainsString( 'id="krivo-chatflow-root"', $html );
		$this->assertStringContainsString( 'data-kcf-flow="' . $id . '"', $html );
		$this->assertMatchesRegularExpression( '/<div id="krivo-chatflow-root"[^>]* hidden>/', $html, 'Closed bubble, hidden until the loader decides' );
		$this->assertStringContainsString( 'aria-expanded="false"', $html );
		$this->assertStringContainsString( 'aria-controls="krivo-chatflow-panel"', $html );
		$this->assertStringNotContainsString( 'style=', $html, 'No inline style attributes: CSP friendly' );
		$this->assertStringNotContainsString( '<style', $html );
		$this->assertStringNotContainsString( 'onclick', $html );
	}

	public function test_the_config_block_is_non_executable_json_and_cannot_break_out_of_its_script(): void {
		$this->published( array(), array( 'design' => array( 'title' => '</script><script>alert(1)</script>' ) ) );
		$this->visit();
		$html = $this->footer();

		$this->assertStringContainsString( 'type="application/json"', $html );
		$this->assertStringContainsString( 'id="krivo-chatflow-config"', $html );
		preg_match( '#<script[^>]*id="krivo-chatflow-config"[^>]*>(.*?)</script>#s', $html, $match );
		$this->assertNotEmpty( $match, 'The JSON block is one well-formed script element' );
		$config = json_decode( $match[1], true );
		$this->assertIsArray( $config, 'Valid JSON' );
		$this->assertStringNotContainsString( '<', $match[1], 'Angle brackets are escaped in the JSON' );
		$this->assertStringNotContainsString( 'alert(1)</script>', $html );
		$this->assertSame( KRIVO_CHATFLOW_VERSION, $config['version'] );
		$this->assertStringContainsString( 'krivo-chatflow/v1', $config['rest'] );
		$this->assertStringContainsString( 'admin-ajax.php', $config['ajax'] );
		$this->assertStringEndsWith( 'panel.js?ver=' . KRIVO_CHATFLOW_VERSION, $config['panel']['js'] );
		$this->assertFalse( $config['datalayer'], 'The dataLayer mirror is off by default' );
		$this->assertFalse( $config['preview'] );
	}

	public function test_the_page_never_receives_anything_private(): void {
		$this->published();
		$this->visit();
		$html = $this->footer();
		foreach ( array( 'sales@example.test', 'vip@example.test', '5511999998888', '5521988887777', 'dst_sales', 'dst_vip01', 'automations', 'send_email', 'admin_email', get_option( 'admin_email' ) ) as $private ) {
			$this->assertStringNotContainsString( $private, $html, $private );
		}
	}

	public function test_dev_event_mirroring_is_only_enabled_by_the_administrator(): void {
		$this->published();
		Settings::update( array( 'events' => array( 'datalayer' => true ) ) );
		$this->visit();
		preg_match( '#id="krivo-chatflow-config"[^>]*>(.*?)</script>#s', $this->footer(), $match );
		$this->assertTrue( json_decode( $match[1], true )['datalayer'] );
	}

	public function test_the_signature_is_absent_until_authorised_and_the_link_needs_a_second_consent(): void {
		$this->published();
		$this->visit();
		preg_match( '#id="krivo-chatflow-config"[^>]*>(.*?)</script>#s', $this->footer(), $match );
		$this->assertNull( json_decode( $match[1], true )['flow']['signature'] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		Settings::update( array( 'branding' => array( 'signature_enabled' => true ) ) );
		Index::rebuild();
		wp_set_current_user( 0 );
		$this->visit();
		preg_match( '#id="krivo-chatflow-config"[^>]*>(.*?)</script>#s', $this->footer(), $match );
		$signature = json_decode( $match[1], true )['flow']['signature'];
		$this->assertSame( 'Krivo Works', $signature['name'] );
		$this->assertSame( '', $signature['link'], 'Text only: the link is a separate authorisation' );
	}

	public function test_bubble_markup_escapes_everything_an_administrator_typed(): void {
		$boot = array(
			'id'     => 7,
			'bubble' => array(
				'label'     => 'Chat "now" <b>x</b>',
				'shape'     => 'circle',
				'position'  => 'bottom-right',
				'animation' => 'pulse',
				'shadow'    => true,
				'icon'      => array( 'type' => 'chat', 'svg' => '', 'image_url' => '' ),
				'tooltip'   => array( 'enabled' => true, 'text' => '<img src=x onerror=alert(1)>Hi', 'dismissible' => true ),
				'badge'     => array( 'enabled' => true, 'text' => '<i>1</i>' ),
			),
		);
		$html = Renderer::bubble( $boot );
		$this->assertStringNotContainsString( '<img src=x', $html );
		$this->assertStringNotContainsString( '<b>x</b>', $html );
		$this->assertStringNotContainsString( '<i>1</i>', $html );
		$this->assertStringContainsString( 'kcf-anim-pulse', $html );
		$this->assertStringContainsString( 'kcf-shadow', $html );
		$this->assertStringContainsString( 'kcf-badge', $html );
	}

	public function test_pill_bubbles_show_their_text_and_other_shapes_do_not(): void {
		$boot = array(
			'id'     => 1,
			'bubble' => array( 'label' => 'Fale conosco', 'shape' => 'pill', 'position' => 'bottom-left', 'animation' => 'none', 'shadow' => false, 'icon' => array( 'type' => 'headset', 'svg' => '', 'image_url' => '' ), 'tooltip' => array( 'enabled' => false ), 'badge' => array( 'enabled' => false ) ),
		);
		$this->assertStringContainsString( '<span class="kcf-label">Fale conosco</span>', Renderer::bubble( $boot ) );
		$boot['bubble']['shape'] = 'circle';
		$html                    = Renderer::bubble( $boot );
		$this->assertStringNotContainsString( 'kcf-label', $html );
		$this->assertStringContainsString( 'aria-label="Fale conosco"', $html );
		$this->assertStringContainsString( 'kcf-pos-bottom-left', $html );
	}

	public function test_custom_svg_is_filtered_again_at_output_and_images_are_escaped(): void {
		$boot = array(
			'id'     => 1,
			'bubble' => array(
				'label' => 'x', 'shape' => 'circle', 'position' => 'bottom-right', 'animation' => 'none', 'shadow' => false,
				'icon' => array( 'type' => 'custom_svg', 'svg' => '<svg viewBox="0 0 10 10"><script>alert(1)</script><path d="M0 0h10" onload="x()"/></svg>', 'image_url' => '' ),
				'tooltip' => array( 'enabled' => false ), 'badge' => array( 'enabled' => false ),
			),
		);
		$html = Renderer::bubble( $boot );
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringNotContainsString( 'onload', $html );
		$this->assertStringContainsString( '<path', $html );

		$boot['bubble']['icon'] = array( 'type' => 'custom_image', 'svg' => '', 'image_url' => 'javascript:alert(1)' );
		$this->assertStringNotContainsString( 'javascript:', Renderer::bubble( $boot ) );
	}

	// ------------------------------------------------------------------ shortcode

	public function test_shortcode_renders_a_host_and_loads_the_assets_only_where_it_is_used(): void {
		$id = $this->published( array( 'mode' => 'none' ) );
		$this->visit();
		$this->assertFalse( wp_script_is( Assets::HANDLE, 'enqueued' ), 'Not enqueued until the shortcode runs' );

		$out = do_shortcode( '[krivo_chatflow id="' . $id . '"]' );
		$this->assertStringContainsString( 'data-kcf-inline="' . $id . '"', $out );
		$this->assertTrue( wp_script_is( Assets::HANDLE, 'enqueued' ) );

		preg_match( '#id="krivo-chatflow-config"[^>]*>(.*?)</script>#s', $this->footer(), $match );
		$config = json_decode( $match[1], true );
		$this->assertArrayHasKey( (string) $id, $config['inline'] );
		$this->assertNull( $config['flow'], 'No floating bubble for a manual flow' );
	}

	public function test_shortcode_ignores_drafts_unknown_ids_and_bad_input(): void {
		$draft = FlowRepository::create( array( 'name' => 'Draft' ), 1 );
		$this->visit();
		$this->assertSame( '', do_shortcode( '[krivo_chatflow id="' . $draft . '"]' ) );
		$this->assertSame( '', do_shortcode( '[krivo_chatflow id="99999"]' ) );
		$this->assertSame( '', do_shortcode( '[krivo_chatflow id="abc"]' ) );
		$this->assertSame( '', do_shortcode( '[krivo_chatflow]' ) );
		$this->assertFalse( wp_script_is( Assets::HANDLE, 'enqueued' ) );
		$this->assertSame( 'krivo_chatflow', Shortcode::TAG );
	}
}
