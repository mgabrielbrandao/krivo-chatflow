<?php
/**
 * Plugin Name:       Krivo ChatFlow
 * Description:       A floating chat widget that qualifies visitors with a short flow, saves the lead and continues by WhatsApp, e-mail or webhook.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            Krivo Works
 * Author URI:        https://krivo.works/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       krivo-chatflow
 * Domain Path:       /languages
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow;

defined( 'ABSPATH' ) || exit;

define( 'KRIVO_CHATFLOW_VERSION', '0.1.0' );
define( 'KRIVO_CHATFLOW_DB_VERSION', '1' );
define( 'KRIVO_CHATFLOW_API_VERSION', 1 );
define( 'KRIVO_CHATFLOW_MIN_PHP', '7.4' );
define( 'KRIVO_CHATFLOW_MIN_WP', '6.5' );
define( 'KRIVO_CHATFLOW_FILE', __FILE__ );
define( 'KRIVO_CHATFLOW_DIR', plugin_dir_path( __FILE__ ) );
define( 'KRIVO_CHATFLOW_URL', plugin_dir_url( __FILE__ ) );
define( 'KRIVO_CHATFLOW_CREDIT_URL', 'https://krivo.works/' );

if ( version_compare( PHP_VERSION, KRIVO_CHATFLOW_MIN_PHP, '<' ) || version_compare( get_bloginfo( 'version' ), KRIVO_CHATFLOW_MIN_WP, '<' ) ) {
	add_action(
		'admin_notices',
		static function () {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: required PHP version, 2: required WordPress version. */
						__( 'Krivo ChatFlow requires PHP %1$s and WordPress %2$s or newer. The plugin is inactive.', 'krivo-chatflow' ),
						KRIVO_CHATFLOW_MIN_PHP,
						KRIVO_CHATFLOW_MIN_WP
					)
				)
			);
		}
	);
	return;
}

require_once KRIVO_CHATFLOW_DIR . 'includes/Autoloader.php';
Autoloader::register( KRIVO_CHATFLOW_DIR . 'includes/' );

register_activation_hook( __FILE__, array( Infrastructure\Db\Installer::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Infrastructure\Db\Installer::class, 'deactivate' ) );

Plugin::boot();
