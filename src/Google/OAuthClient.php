<?php
/**
 * Autenticazione OAuth2 con Google.
 *
 * @package Mavida\BookACall
 */

declare(strict_types=1);

namespace Mavida\BookACall\Google;

use Mavida\BookACall\Admin\AdminMenu;
use Mavida\BookACall\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Flusso "authorization code" con client OAuth fornito dall'admin e refresh token cifrato.
 */
final class OAuthClient {

	/**
	 * Endpoint di autorizzazione.
	 *
	 * @var string
	 */
	private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

	/**
	 * Endpoint dei token.
	 *
	 * @var string
	 */
	private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

	/**
	 * Transient che contiene l'access token corrente.
	 *
	 * @var string
	 */
	private const TOKEN_TRANSIENT = 'wpbac_google_access_token';

	/**
	 * Aggancia il callback OAuth.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_wpbac_google_callback', array( $this, 'handle_callback' ) );
	}

	/**
	 * URI di redirect da autorizzare nella Google Cloud Console.
	 *
	 * @return string
	 */
	public function redirect_uri(): string {
		return admin_url( 'admin-post.php?action=wpbac_google_callback' );
	}

	/**
	 * URL a cui mandare l'admin per concedere l'accesso.
	 *
	 * @return string
	 */
	public function auth_url(): string {
		return add_query_arg(
			array(
				'client_id'     => (string) Settings::get( 'google_client_id' ),
				'redirect_uri'  => $this->redirect_uri(),
				'response_type' => 'code',
				'scope'         => 'https://www.googleapis.com/auth/calendar.events https://www.googleapis.com/auth/calendar.freebusy',
				'access_type'   => 'offline',
				'prompt'        => 'consent',
				'state'         => wp_create_nonce( 'wpbac_google_oauth' ),
			),
			self::AUTH_URL
		);
	}

	/**
	 * Callback: scambia il code con i token e li salva.
	 *
	 * @return void
	 */
	public function handle_callback(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permessi insufficienti.', 'wp-book-a-call' ), 403 );
		}

		// Il nonce viaggia nel parametro "state" dell'OAuth.
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		if ( ! wp_verify_nonce( $state, 'wpbac_google_oauth' ) ) {
			wp_die( esc_html__( 'Richiesta non valida.', 'wp-book-a-call' ), 403 );
		}

		$code   = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		$denied = isset( $_GET['error'] ) ? sanitize_key( wp_unslash( $_GET['error'] ) ) : '';

		// Esito: "ok" oppure il motivo dell'errore (access_denied, invalid_client, redirect_uri_mismatch...).
		if ( '' !== $denied ) {
			$reason = $denied;
		} elseif ( '' === $code ) {
			$reason = 'no_code';
		} else {
			$reason = $this->exchange_code( $code );
		}

		$args = 'ok' === $reason ? array( 'wpbac_google' => 'ok' ) : array(
			'wpbac_google' => 'error',
			'wpbac_reason' => $reason,
		);
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php?page=' . AdminMenu::MENU_SLUG ) ) );
		exit;
	}

	/**
	 * Scambia il code con access e refresh token.
	 *
	 * @param string $code Authorization code.
	 * @return string "ok" oppure il codice dell'errore.
	 */
	private function exchange_code( string $code ): string {
		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 15,
				'body'    => array(
					'code'          => $code,
					'client_id'     => (string) Settings::get( 'google_client_id' ),
					'client_secret' => (string) Settings::get( 'google_client_secret' ),
					'redirect_uri'  => $this->redirect_uri(),
					'grant_type'    => 'authorization_code',
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return 'network';
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['refresh_token'] ) ) {
			// Google spiega l'errore nel campo "error" (invalid_client, invalid_grant, redirect_uri_mismatch...).
			return isset( $body['error'] ) ? sanitize_key( (string) $body['error'] ) : 'no_refresh_token';
		}

		Settings::update( array( 'google_refresh_token' => $body['refresh_token'] ) );
		if ( ! empty( $body['access_token'] ) ) {
			set_transient( self::TOKEN_TRANSIENT, $body['access_token'], max( 60, (int) ( $body['expires_in'] ?? 3600 ) - 120 ) );
		}
		return 'ok';
	}

	/**
	 * Access token valido (rinnovato con il refresh token quando serve).
	 *
	 * @return string|\WP_Error
	 */
	public function access_token(): string|\WP_Error {
		$cached = get_transient( self::TOKEN_TRANSIENT );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}
		if ( ! Settings::google_connected() ) {
			return new \WP_Error( 'wpbac_google_not_connected', 'Google non collegato.' );
		}

		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 15,
				'body'    => array(
					'client_id'     => (string) Settings::get( 'google_client_id' ),
					'client_secret' => (string) Settings::get( 'google_client_secret' ),
					'refresh_token' => (string) Settings::get( 'google_refresh_token' ),
					'grant_type'    => 'refresh_token',
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['access_token'] ) ) {
			return new \WP_Error( 'wpbac_google_token', 'Rinnovo del token Google non riuscito: ' . wp_remote_retrieve_body( $response ) );
		}

		set_transient( self::TOKEN_TRANSIENT, $body['access_token'], max( 60, (int) ( $body['expires_in'] ?? 3600 ) - 120 ) );
		return $body['access_token'];
	}

	/**
	 * Scollega Google: cancella credenziali e token.
	 *
	 * @return void
	 */
	public function disconnect(): void {
		Settings::update(
			array(
				'google_refresh_token' => '',
				'google_account'       => '',
			)
		);
		delete_transient( self::TOKEN_TRANSIENT );
	}
}
