<?php
/**
 * Menu e pagina di amministrazione.
 *
 * @package Mavida\BookACall
 */

declare(strict_types=1);

namespace Mavida\BookACall\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Registra il menu "Book a Call" e carica l'app React.
 */
final class AdminMenu {

	/**
	 * Slug del menu.
	 *
	 * @var string
	 */
	public const MENU_SLUG = 'wpbac';

	/**
	 * Aggancia gli hook.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Aggiunge la voce di menu.
	 *
	 * @return void
	 */
	public function add_menu(): void {
		add_menu_page(
			__( 'Book a Call', 'wp-book-a-call' ),
			__( 'Book a Call', 'wp-book-a-call' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_page' ),
			'dashicons-calendar-alt',
			58
		);
	}

	/**
	 * Stampa il mount point dell'app React.
	 *
	 * @return void
	 */
	public function render_page(): void {
		echo '<div class="wrap"><div id="wpbac-admin-root"></div></div>';
	}

	/**
	 * Carica gli asset solo nella pagina del plugin.
	 *
	 * @param string $hook_suffix Suffisso della pagina admin corrente.
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( ! str_contains( $hook_suffix, self::MENU_SLUG ) ) {
			return;
		}

		$asset_file = WPBAC_PLUGIN_DIR . 'build/admin/index.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			return;
		}
		$asset = require $asset_file;

		wp_enqueue_script(
			'wpbac-admin',
			WPBAC_PLUGIN_URL . 'build/admin/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);
		wp_enqueue_style( 'wp-components' );
		wp_set_script_translations( 'wpbac-admin', 'wp-book-a-call', WPBAC_PLUGIN_DIR . 'languages' );

		// Dati per il client JS.
		wp_localize_script(
			'wpbac-admin',
			'wpbacAdmin',
			array(
				'nonce'        => wp_create_nonce( 'wp_rest' ),
				'apiRoot'      => esc_url_raw( rest_url( 'wpbac/v1' ) ),
				'siteTimezone' => wp_timezone_string(),
			)
		);
	}
}
