<?php
/**
 * Base dei controller REST.
 *
 * @package Mavida\BookACall
 */

declare(strict_types=1);

namespace Mavida\BookACall\REST;

defined( 'ABSPATH' ) || exit;

/**
 * Namespace comune, permessi e risposte senza cache.
 */
abstract class RestController {

	/**
	 * Namespace delle rotte.
	 *
	 * @var string
	 */
	protected const API_NAMESPACE = 'wpbac/v1';

	/**
	 * Registra le rotte.
	 *
	 * @return void
	 */
	abstract public function register_routes(): void;

	/**
	 * Aggancia la registrazione delle rotte.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Impedisce a LiteSpeed Cache (e a qualunque cache di pagina) di memorizzare le risposte del plugin.
	 * Vale anche per gli errori, che non passano da respond().
	 *
	 * @param \WP_HTTP_Response|\WP_Error $result  Risposta.
	 * @param \WP_REST_Server             $server  Server REST.
	 * @param \WP_REST_Request            $request Richiesta.
	 * @return \WP_HTTP_Response|\WP_Error
	 */
	public static function no_cache( $result, \WP_REST_Server $server, \WP_REST_Request $request ) {
		if ( str_starts_with( $request->get_route(), '/' . self::API_NAMESPACE . '/' ) ) {
			// Hook ufficiale di LiteSpeed Cache + header equivalente per la cache a livello server.
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Hook di LiteSpeed Cache.
			do_action( 'litespeed_control_set_nocache', 'wp-book-a-call REST' );
			$server->send_header( 'Cache-Control', 'no-store, max-age=0' );
			$server->send_header( 'X-LiteSpeed-Cache-Control', 'no-cache' );
		}
		return $result;
	}

	/**
	 * Solo chi può gestire le opzioni.
	 *
	 * @return bool|\WP_Error
	 */
	public function check_admin_permission(): bool|\WP_Error {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		return new \WP_Error( 'wpbac_forbidden', __( 'Permessi insufficienti.', 'wp-book-a-call' ), array( 'status' => rest_authorization_required_code() ) );
	}

	/**
	 * Risposta JSON che le cache di pagina (es. LiteSpeed) non devono memorizzare.
	 *
	 * @param mixed $data   Dati.
	 * @param int   $status Codice HTTP.
	 * @return \WP_REST_Response
	 */
	protected function respond( mixed $data, int $status = 200 ): \WP_REST_Response {
		$response = new \WP_REST_Response( $data, $status );
		$response->header( 'Cache-Control', 'no-store, max-age=0' );
		return $response;
	}
}
