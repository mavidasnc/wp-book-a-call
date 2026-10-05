<?php
/**
 * Client minimale per l'API di Resend.
 *
 * @package Mavida\BookACall
 */

declare(strict_types=1);

namespace Mavida\BookACall\Email;

use Mavida\BookACall\Support\Logger;
use Mavida\BookACall\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Invio (anche programmato) e annullamento di email tramite https://api.resend.com.
 */
final class ResendClient {

	/**
	 * Base dell'API.
	 *
	 * @var string
	 */
	private const API = 'https://api.resend.com';

	/**
	 * Costruttore.
	 *
	 * @param ResendStatus $status Stato dell'integrazione.
	 */
	public function __construct( private readonly ResendStatus $status ) {}

	/**
	 * Stato dell'integrazione.
	 *
	 * @return ResendStatus
	 */
	public function status(): ResendStatus {
		return $this->status;
	}

	/**
	 * Resend va usato per l'invio?
	 *
	 * @phpstan-impure Lo stato cambia se un invio fallisce.
	 * @return bool
	 */
	public function is_active(): bool {
		return $this->status->is_active();
	}

	/**
	 * Corpo JSON di POST /emails (funzione pura, senza accesso a WordPress).
	 *
	 * @param array<string,mixed> $message Campi: from_email, from_name, to[], subject, html, text, reply{email,name}|null, ics, method, scheduled_at.
	 * @return array<string,mixed>
	 */
	public static function build_payload( array $message ): array {
		$name = trim( str_replace( array( '"', '<', '>', "\r", "\n" ), '', (string) ( $message['from_name'] ?? '' ) ) );
		$from = '' !== $name ? sprintf( '%s <%s>', $name, $message['from_email'] ) : (string) $message['from_email'];

		$payload = array(
			'from'    => $from,
			'to'      => array_values( (array) $message['to'] ),
			'subject' => (string) $message['subject'],
			'html'    => (string) $message['html'],
			'text'    => (string) $message['text'],
		);

		if ( ! empty( $message['reply']['email'] ) ) {
			$payload['reply_to'] = array( (string) $message['reply']['email'] );
		}

		// Allegato iCal. Resend non accetta allegati sugli invii programmati: i promemoria non ne hanno.
		if ( ! empty( $message['ics'] ) ) {
			$payload['attachments'] = array(
				array(
					'filename'     => 'invito.ics',
					// Contenuto binario in base64: richiesto dall'API, non è offuscamento.
					'content'      => base64_encode( (string) $message['ics'] ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
					'content_type' => 'text/calendar; charset=UTF-8; method=' . ( $message['method'] ?? 'REQUEST' ),
				),
			);
		}

		if ( ! empty( $message['scheduled_at'] ) ) {
			$payload['scheduled_at'] = (string) $message['scheduled_at'];
		}

		return $payload;
	}

	/**
	 * Invia (o programma) una email. In caso di errore Resend viene disattivato e l'errore registrato.
	 *
	 * @param array<string,mixed> $prepared     Messaggio pronto (to, subject, html, text, reply, ics, method, scheduled_at).
	 * @param bool                $notify_owner False per le prove manuali.
	 * @return array{id:string}|\WP_Error
	 */
	public function send( array $prepared, bool $notify_owner = true ): array|\WP_Error {
		$message = $prepared + array(
			'from_email' => Settings::from_email(),
			'from_name'  => Settings::host_name(),
		);

		$result = $this->request( 'POST', '/emails', self::build_payload( $message ) );
		if ( is_wp_error( $result ) ) {
			Logger::log( 'Resend: ' . $result->get_error_message() );
			$this->status->record_error( $result->get_error_message(), $notify_owner );
			return $result;
		}
		return array( 'id' => (string) ( $result['id'] ?? '' ) );
	}

	/**
	 * Annulla un invio programmato. Gli errori non disattivano Resend (l'email potrebbe essere già partita).
	 *
	 * @param string $id Id restituito da send().
	 * @return bool
	 */
	public function cancel( string $id ): bool {
		if ( '' === $id ) {
			return false;
		}
		return ! is_wp_error( $this->request( 'POST', '/emails/' . rawurlencode( $id ) . '/cancel', array() ) );
	}

	/**
	 * Chiamata all'API con la chiave salvata.
	 *
	 * @param string              $method HTTP method.
	 * @param string              $path   Percorso.
	 * @param array<string,mixed> $body   Corpo JSON.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function request( string $method, string $path, array $body ): array|\WP_Error {
		$key = (string) Settings::get( 'resend_api_key' );
		if ( '' === $key ) {
			return new \WP_Error( 'wpbac_resend_no_key', __( 'Chiave API di Resend mancante.', 'wp-book-a-call' ) );
		}

		$response = wp_remote_request(
			self::API . $path,
			array(
				'method'  => $method,
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'wpbac_resend_network', $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		$data = is_array( $data ) ? $data : array();
		if ( $code >= 400 ) {
			$detail = (string) ( $data['message'] ?? wp_remote_retrieve_body( $response ) );
			return new \WP_Error( 'wpbac_resend_api', sprintf( 'Resend (HTTP %d): %s', $code, $detail ) );
		}
		return $data;
	}
}
