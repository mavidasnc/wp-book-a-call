<?php
/**
 * Webhook verso sistemi esterni (es. n8n).
 *
 * @package Mavida\BookACall
 */

declare(strict_types=1);

namespace Mavida\BookACall\Webhook;

use Mavida\BookACall\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Invia un POST JSON firmato (HMAC SHA-256) a ogni prenotazione creata, spostata o annullata.
 */
final class WebhookSender {

	/**
	 * Aggancia gli eventi del plugin.
	 *
	 * @return void
	 */
	public function register(): void {
		$events = array(
			'wpbac_booking_created'     => 'booking.created',
			'wpbac_booking_rescheduled' => 'booking.rescheduled',
			'wpbac_booking_cancelled'   => 'booking.cancelled',
		);
		foreach ( $events as $action => $name ) {
			add_action(
				$action,
				function ( array $booking, array $event_type ) use ( $name ): void {
					$this->dispatch( $name, $booking, $event_type, false );
				},
				10,
				2
			);
		}
	}

	/**
	 * Firma del corpo, nel formato "sha256=<hex>".
	 *
	 * @param string $body   Corpo JSON.
	 * @param string $secret Segreto condiviso.
	 * @return string
	 */
	public static function sign( string $body, string $secret ): string {
		return 'sha256=' . hash_hmac( 'sha256', $body, $secret );
	}

	/**
	 * Payload JSON di un evento (senza token né dati interni).
	 *
	 * @param string              $event      Nome evento.
	 * @param array<string,mixed> $booking    Prenotazione.
	 * @param array<string,mixed> $event_type Tipo di call.
	 * @return array<string,mixed>
	 */
	public static function payload( string $event, array $booking, array $event_type ): array {
		$answers = array();
		foreach ( (array) $event_type['questions'] as $question ) {
			if ( isset( $booking['answers'][ $question['id'] ] ) && '' !== $booking['answers'][ $question['id'] ] ) {
				$answers[] = array(
					'question' => $question['label'],
					'answer'   => $booking['answers'][ $question['id'] ],
				);
			}
		}

		return array(
			'event'      => $event,
			'sent_at'    => gmdate( 'c' ),
			'site'       => home_url(),
			'booking'    => array(
				'id'       => $booking['id'],
				'status'   => $booking['status'],
				'start'    => gmdate( 'c', $booking['start_ts'] ),
				'end'      => gmdate( 'c', $booking['end_ts'] ),
				'start_ts' => $booking['start_ts'],
				'name'     => $booking['name'],
				'email'    => $booking['email'],
				'timezone' => $booking['timezone'],
				'meet_url' => $booking['meet_url'],
				'answers'  => $answers,
			),
			'event_type' => array(
				'id'            => $event_type['id'],
				'slug'          => $event_type['slug'],
				'title'         => $event_type['title'],
				'duration_min'  => $event_type['duration_min'],
				'location_type' => $event_type['location_type'],
			),
		);
	}

	/**
	 * Invia l'evento al webhook configurato.
	 *
	 * @param string              $event      Nome evento.
	 * @param array<string,mixed> $booking    Prenotazione.
	 * @param array<string,mixed> $event_type Tipo di call.
	 * @param bool                $blocking   Attende la risposta (per il test dall'admin).
	 * @return int|\WP_Error|null Codice HTTP se bloccante, null se non configurato o non bloccante.
	 */
	public function dispatch( string $event, array $booking, array $event_type, bool $blocking ): int|\WP_Error|null {
		$url = (string) Settings::get( 'webhook_url' );
		if ( '' === $url ) {
			return null;
		}

		$body    = (string) wp_json_encode( self::payload( $event, $booking, $event_type ) );
		$headers = array(
			'Content-Type'   => 'application/json',
			'X-Wpbac-Event'  => $event,
			'X-Wpbac-Source' => home_url(),
		);
		$secret  = (string) Settings::get( 'webhook_secret' );
		if ( '' !== $secret ) {
			$headers['X-Wpbac-Signature'] = self::sign( $body, $secret );
		}

		// Non bloccante: un webhook lento non deve rallentare la prenotazione.
		$response = wp_safe_remote_post(
			$url,
			array(
				'timeout'  => 8,
				'blocking' => $blocking,
				'headers'  => $headers,
				'body'     => $body,
			)
		);

		if ( ! $blocking ) {
			return null;
		}
		return is_wp_error( $response ) ? $response : (int) wp_remote_retrieve_response_code( $response );
	}
}
