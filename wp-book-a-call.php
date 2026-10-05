<?php
/**
 * Plugin Name:       Book a call
 * Plugin URI:        https://github.com/mavidasnc/wp-book-a-call
 * Description:       Blocco Gutenberg per prenotare call conoscitive, con slot configurabili, notifiche email con iCal e integrazione opzionale con Google Calendar.
 * Version:           0.6.0
 * Requires at least: 6.5
 * Requires PHP:      8.3
 * Author:            Mavida snc
 * Author URI:        https://mavida.it
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-book-a-call
 * Domain Path:       /languages
 *
 * @package Mavida\BookACall
 */

declare(strict_types=1);

// Blocco accesso diretto.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Costanti del plugin (guard per evitare conflitti con wp-config.php).
if ( ! defined( 'WPBAC_VERSION' ) ) {
	define( 'WPBAC_VERSION', '0.6.0' );
}
if ( ! defined( 'WPBAC_PLUGIN_FILE' ) ) {
	define( 'WPBAC_PLUGIN_FILE', __FILE__ );
}
if ( ! defined( 'WPBAC_PLUGIN_DIR' ) ) {
	define( 'WPBAC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'WPBAC_PLUGIN_URL' ) ) {
	define( 'WPBAC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}
if ( ! defined( 'WPBAC_DEBUG' ) ) {
	define( 'WPBAC_DEBUG', defined( 'WP_DEBUG' ) && WP_DEBUG );
}

// Autoload PSR-4 via Composer.
$wpbac_autoload = WPBAC_PLUGIN_DIR . 'vendor/autoload.php';
if ( ! file_exists( $wpbac_autoload ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__(
				'WP Book a Call: dipendenze Composer non trovate. Esegui "composer install" nella cartella del plugin.',
				'wp-book-a-call'
			);
			echo '</p></div>';
		}
	);
	return;
}
require_once $wpbac_autoload;

// Hook di attivazione: deve stare nel file principale (non dentro un altro hook).
register_activation_hook( __FILE__, array( 'Mavida\\BookACall\\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Mavida\\BookACall\\Deactivator', 'deactivate' ) );

// Inizializzazione su plugins_loaded per garantire che tutti i plugin siano caricati.
add_action(
	'plugins_loaded',
	static function (): void {
		Mavida\BookACall\Plugin::get_instance()->init();
	}
);
