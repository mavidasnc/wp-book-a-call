<?php
/**
 * Registrazione dei blocchi Gutenberg.
 *
 * @package Mavida\BookACall
 */

declare(strict_types=1);

namespace Mavida\BookACall\Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * Registra i blocchi compilati in build/.
 */
final class BlockRegistrar {

	/**
	 * Aggancia gli hook.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_blocks' ) );
	}

	/**
	 * Registra il blocco di prenotazione da build/booking/block.json.
	 *
	 * @return void
	 */
	public function register_blocks(): void {
		register_block_type( WPBAC_PLUGIN_DIR . 'build/booking' );
	}
}
