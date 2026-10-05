<?php
/**
 * Token per i link di gestione della prenotazione.
 *
 * @package Mavida\BookACall
 */

declare(strict_types=1);

namespace Mavida\BookACall\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Il token in chiaro viaggia solo nei link email; nel database resta l'hash HMAC.
 */
final class Token {

	/**
	 * Token di gestione di una prenotazione, derivato (HMAC) da id e salt di WordPress.
	 * Essendo ricalcolabile, si può rimettere nei link dei promemoria senza conservarlo in chiaro.
	 *
	 * @param int $booking_id Id della prenotazione.
	 * @return string
	 */
	public static function for_booking( int $booking_id ): string {
		return hash_hmac( 'sha256', 'manage:' . $booking_id, wp_salt( 'auth' ) );
	}

	/**
	 * Hash HMAC del token.
	 *
	 * @param string $plain Token in chiaro.
	 * @return string
	 */
	public static function hash( string $plain ): string {
		return hash_hmac( 'sha256', $plain, wp_salt( 'auth' ) );
	}

	/**
	 * Confronto a tempo costante tra token in chiaro e hash salvato.
	 *
	 * @param string $plain  Token in chiaro.
	 * @param string $stored Hash salvato.
	 * @return bool
	 */
	public static function verify( string $plain, string $stored ): bool {
		return '' !== $plain && hash_equals( $stored, self::hash( $plain ) );
	}
}
