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
	 * Chiave API mascherata: prefisso, ultimi 4 caratteri e lunghezza (mai la chiave intera).
	 *
	 * @param string $key Chiave in chiaro.
	 * @return string
	 */
	public static function mask_key( string $key ): string {
		if ( '' === $key ) {
			return '(nessuna chiave salvata)';
		}
		$tail = strlen( $key ) > 8 ? substr( $key, -4 ) : '';
		return sprintf( '%s…%s (%d caratteri)', substr( $key, 0, 3 ), $tail, strlen( $key ) );
	}

	/**
	 * Testo con tutti i dati di un invio fallito, da copiare e mandare per un controllo (funzione pura).
	 *
	 * @param array<string,mixed> $message Messaggio inviato (from_email, from_name, to[], subject, reply).
	 * @param array<string,mixed> $context key_hint, from_source (field|admin), saved_from, admin_email, endpoint, http, response, site, versions, time.
	 * @return string
	 */
	public static function build_diagnostic( array $message, array $context ): string {
		$from_name  = trim( (string) ( $message['from_name'] ?? '' ) );
		$from_email = (string) ( $message['from_email'] ?? '' );
		$saved      = '' !== (string) ( $context['saved_from'] ?? '' ) ? (string) $context['saved_from'] : '(vuoto)';
		$source     = 'field' === ( $context['from_source'] ?? '' )
			? 'campo "Email del mittente" delle impostazioni'
			: 'email di amministrazione di WordPress (il campo "Email del mittente" è vuoto o non valido)';
		$domain     = false !== strpos( $from_email, '@' ) ? substr( strrchr( $from_email, '@' ), 1 ) : '';

		$lines = array(
			'Mittente usato nel test: ' . ( '' !== $from_name ? $from_name . ' <' . $from_email . '>' : $from_email ),
			'Dominio del mittente: ' . ( '' !== $domain ? $domain : '(non rilevato)' ),
			'Origine del mittente: ' . $source,
			'Valore salvato nel campo "Email del mittente": ' . $saved,
			'Email di amministrazione di WordPress: ' . (string) ( $context['admin_email'] ?? '' ),
			'Destinatari: ' . implode( ', ', array_map( 'strval', (array) ( $message['to'] ?? array() ) ) ),
		);
		if ( ! empty( $message['reply']['email'] ) ) {
			$lines[] = 'Reply-To: ' . $message['reply']['email'];
		}
		$lines[] = 'Oggetto: ' . (string) ( $message['subject'] ?? '' );
		$lines[] = 'Chiave API: ' . (string) ( $context['key_hint'] ?? '' );
		$lines[] = 'Chiamata: ' . (string) ( $context['endpoint'] ?? '' );
		$lines[] = 'Risposta di Resend: HTTP ' . (int) ( $context['http'] ?? 0 ) . ' ' . (string) ( $context['response'] ?? '' );
		$lines[] = 'Sito: ' . (string) ( $context['site'] ?? '' ) . ' (' . (string) ( $context['versions'] ?? '' ) . ')';
		$lines[] = 'Data: ' . (string) ( $context['time'] ?? '' );

		return implode( "\n", $lines );
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

		$payload = self::build_payload( $message );
		$result  = $this->request( 'POST', '/emails', $payload );
		if ( is_wp_error( $result ) ) {
			// Dati completi del tentativo (mittente, destinatari, risposta di Resend) per capire cosa non va.
			$data       = (array) $result->get_error_data();
			$diagnostic = self::build_diagnostic(
				$message,
				array(
					'key_hint'    => self::mask_key( (string) Settings::get( 'resend_api_key' ) ),
					'from_source' => Settings::from_email_source(),
					'saved_from'  => (string) Settings::get( 'from_email' ),
					'admin_email' => (string) get_option( 'admin_email' ),
					'endpoint'    => 'POST ' . self::API . '/emails',
					'http'        => (int) ( $data['http'] ?? 0 ),
					'response'    => (string) ( $data['body'] ?? $result->get_error_message() ),
					'site'        => home_url(),
					'versions'    => 'plugin ' . WPBAC_VERSION . ', WordPress ' . get_bloginfo( 'version' ) . ', PHP ' . PHP_VERSION,
					'time'        => gmdate( 'Y-m-d H:i:s' ) . ' UTC',
				)
			);
			Logger::log( 'Resend: ' . $result->get_error_message() . ' | from: ' . ( $payload['from'] ?? '' ) );
			$this->status->record_error( $result->get_error_message(), $notify_owner, $diagnostic );
			return new \WP_Error( $result->get_error_code(), $result->get_error_message(), $data + array( 'diagnostic' => $diagnostic ) );
		}
		return array( 'id' => (string) ( $result['id'] ?? '' ) );
	}

	/**
	 * La chiave può annullare gli invii programmati? Le chiavi "solo invio" di Resend no: si prova
	 * ad annullare un id inesistente e si guarda se l'errore è di permessi.
	 *
	 * @return bool
	 */
	public function can_cancel(): bool {
		$result = $this->request( 'POST', '/emails/00000000-0000-0000-0000-000000000000/cancel', array() );
		return ! ( is_wp_error( $result ) && 'wpbac_resend_restricted' === $result->get_error_code() );
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
			return new \WP_Error(
				'wpbac_resend_network',
				$response->get_error_message(),
				array(
					'http' => 0,
					'body' => $response->get_error_message(),
				)
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );
		$data = is_array( $data ) ? $data : array();
		// Codice HTTP e corpo grezzo restano nell'errore: servono alla diagnostica.
		$info = array(
			'http' => $code,
			'body' => $raw,
		);
		if ( in_array( $code, array( 401, 403 ), true ) && 'restricted_api_key' === ( $data['name'] ?? '' ) ) {
			return new \WP_Error( 'wpbac_resend_restricted', sprintf( 'Resend (HTTP %d): %s', $code, (string) ( $data['message'] ?? '' ) ), $info );
		}
		if ( $code >= 400 ) {
			$detail = (string) ( $data['message'] ?? $raw );
			return new \WP_Error( 'wpbac_resend_api', sprintf( 'Resend (HTTP %d): %s', $code, $detail ), $info );
		}
		return $data;
	}
}
