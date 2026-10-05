<?php
/**
 * Impostazioni del plugin.
 *
 * @package Mavida\BookACall
 */

declare(strict_types=1);

namespace Mavida\BookACall\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Accesso all'unica option `wpbac_settings`. I segreti Google sono cifrati.
 */
final class Settings {

	/**
	 * Nome dell'option.
	 *
	 * @var string
	 */
	public const OPTION = 'wpbac_settings';

	/**
	 * Chiavi che contengono segreti (salvate cifrate, mai restituite al client).
	 *
	 * @var string[]
	 */
	public const SECRET_KEYS = array( 'google_client_secret', 'google_refresh_token', 'webhook_secret' );

	/**
	 * Valori di default.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'notify_enabled'           => true,
			'notify_recipients'        => (string) get_option( 'admin_email' ),
			'client_email_enabled'     => true,
			'thanks_message'           => "Grazie per aver prenotato! Non vedo l'ora di sentirci.",
			'max_per_day'              => 2,
			'one_active_per_client'    => true,
			'reminder_24h'             => true,
			'reminder_1h'              => false,
			'webhook_url'              => '',
			'webhook_secret'           => '',
			'host_name'                => '',
			'privacy_url'              => '',
			'delete_data_on_uninstall' => false,
			'google_client_id'         => '',
			'google_client_secret'     => '',
			'google_refresh_token'     => '',
			'google_account'           => '',
			'google_calendar_id'       => 'primary',
			'google_use_busy'          => true,
			'google_use_meet'          => true,
		);
	}

	/**
	 * Tutte le impostazioni (i segreti restano cifrati).
	 *
	 * @return array<string,mixed>
	 */
	public static function all(): array {
		$saved = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	/**
	 * Singolo valore (i segreti vengono decifrati).
	 *
	 * @param string $key Chiave.
	 * @return mixed
	 */
	public static function get( string $key ): mixed {
		$all   = self::all();
		$value = $all[ $key ] ?? null;
		if ( in_array( $key, self::SECRET_KEYS, true ) && is_string( $value ) ) {
			return Crypto::decrypt( $value );
		}
		return $value;
	}

	/**
	 * Nome mostrato come organizzatore.
	 *
	 * @return string
	 */
	public static function host_name(): string {
		$name = (string) self::get( 'host_name' );
		return '' !== $name ? $name : (string) get_bloginfo( 'name' );
	}

	/**
	 * Destinatari delle notifiche admin, già validati.
	 *
	 * @return string[]
	 */
	public static function recipients(): array {
		$list = array_map( 'trim', explode( ',', (string) self::get( 'notify_recipients' ) ) );
		return array_values( array_filter( $list, 'is_email' ) );
	}

	/**
	 * Aggiorna una o più chiavi; i segreti arrivano in chiaro e vengono cifrati.
	 *
	 * @param array<string,mixed> $values Nuovi valori.
	 * @return void
	 */
	public static function update( array $values ): void {
		$current  = self::all();
		$defaults = self::defaults();

		foreach ( $values as $key => $value ) {
			if ( ! array_key_exists( $key, $defaults ) ) {
				continue;
			}
			if ( in_array( $key, self::SECRET_KEYS, true ) ) {
				$current[ $key ] = Crypto::encrypt( (string) $value );
			} elseif ( is_bool( $defaults[ $key ] ) ) {
				$current[ $key ] = (bool) $value;
			} elseif ( is_int( $defaults[ $key ] ) ) {
				$current[ $key ] = min( 50, absint( $value ) );
			} elseif ( 'thanks_message' === $key ) {
				$current[ $key ] = sanitize_textarea_field( (string) $value );
			} elseif ( in_array( $key, array( 'privacy_url', 'webhook_url' ), true ) ) {
				$current[ $key ] = esc_url_raw( (string) $value );
			} else {
				$current[ $key ] = sanitize_text_field( (string) $value );
			}
		}

		update_option( self::OPTION, $current, false );
	}

	/**
	 * Google è collegato (credenziali e refresh token presenti)?
	 *
	 * @return bool
	 */
	public static function google_connected(): bool {
		return '' !== self::get( 'google_client_id' )
			&& '' !== self::get( 'google_client_secret' )
			&& '' !== self::get( 'google_refresh_token' );
	}
}
