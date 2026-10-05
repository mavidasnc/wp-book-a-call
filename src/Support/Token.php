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
	 * Genera un token casuale e il relativo hash.
	 *
	 * @return array{0:string,1:string} Token in chiaro e hash.
	 */
	public static function generate(): array {
		$plain = bin2hex( random_bytes( 32 ) );
		return array( $plain, self::hash( $plain ) );
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
