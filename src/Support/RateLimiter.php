<?php
/**
 * Rate limit basato su transient.
 *
 * @package Mavida\BookACall
 */

declare(strict_types=1);

namespace Mavida\BookACall\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Limita il numero di richieste per client in una finestra di tempo.
 */
final class RateLimiter {

	/**
	 * Hash dell'IP del client (l'IP in chiaro non viene mai salvato).
	 *
	 * @return string
	 */
	public static function client_hash(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) );
	}

	/**
	 * Registra un tentativo e dice se rientra nel limite.
	 *
	 * @param string $bucket Nome del contatore.
	 * @param int    $limit  Tentativi massimi nella finestra.
	 * @param int    $window Durata della finestra in secondi.
	 * @return bool True se consentito.
	 */
	public static function hit( string $bucket, int $limit, int $window ): bool {
		$key   = 'wpbac_rl_' . substr( hash( 'sha256', $bucket . self::client_hash() ), 0, 32 );
		$count = (int) get_transient( $key );
		if ( $count >= $limit ) {
			return false;
		}
		set_transient( $key, $count + 1, $window );
		return true;
	}
}
