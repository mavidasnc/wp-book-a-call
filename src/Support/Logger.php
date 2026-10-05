<?php
/**
 * Log di debug.
 *
 * @package Mavida\BookACall
 */

declare(strict_types=1);

namespace Mavida\BookACall\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Scrive su error_log solo con WP_DEBUG attivo.
 */
final class Logger {

	/**
	 * Registra un messaggio.
	 *
	 * @param string $message Messaggio.
	 * @return void
	 */
	public static function log( string $message ): void {
		if ( WPBAC_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Log volutamente attivo solo in debug.
			error_log( '[wp-book-a-call] ' . $message );
		}
	}
}
