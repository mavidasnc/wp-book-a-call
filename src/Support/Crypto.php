<?php
/**
 * Cifratura dei segreti salvati nel database.
 *
 * @package Mavida\BookACall
 */

declare(strict_types=1);

namespace Mavida\BookACall\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Cifra con libsodium (secretbox), usando una chiave derivata dai salt di WordPress.
 */
final class Crypto {

	/**
	 * Chiave a 32 byte derivata da wp_salt().
	 *
	 * @return string
	 */
	private static function key(): string {
		return hash( 'sha256', wp_salt( 'auth' ) . 'wpbac', true );
	}

	/**
	 * Cifra una stringa e la restituisce in base64.
	 *
	 * @param string $plain Testo in chiaro.
	 * @return string
	 */
	public static function encrypt( string $plain ): string {
		if ( '' === $plain ) {
			return '';
		}
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Serializzazione binaria, non offuscamento.
		return base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, self::key() ) );
	}

	/**
	 * Decifra una stringa prodotta da encrypt(); stringa vuota se non valida.
	 *
	 * @param string $encoded Testo cifrato in base64.
	 * @return string
	 */
	public static function decrypt( string $encoded ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Serializzazione binaria, non offuscamento.
		$raw = base64_decode( $encoded, true );
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}
		$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plain  = sodium_crypto_secretbox_open( $cipher, $nonce, self::key() );
		return false === $plain ? '' : $plain;
	}
}
