<?php
/**
 * Client minimale per Google Calendar API v3.
 *
 * @package Mavida\BookACall
 */

declare(strict_types=1);

namespace Mavida\BookACall\Google;

use Mavida\BookACall\Support\Logger;
use Mavida\BookACall\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Chiamate REST dirette via wp_remote_request (nessun SDK).
 */
final class CalendarClient {

	/**
	 * Base URL dell'API.
	 *
	 * @var string
	 */
	private const API = 'https://www.googleapis.com/calendar/v3';

	/**
	 * Costruttore.
	 *
	 * @param OAuthClient $oauth Gestore dei token.
	 */
	public function __construct( private readonly OAuthClient $oauth ) {}

	/**
	 * L'integrazione è collegata?
	 *
	 * @return bool
	 */
	public function is_connected(): bool {
		return Settings::google_connected();
	}

	/**
	 * ID del calendario configurato.
	 *
	 * @return string
	 */
	private function calendar_id(): string {
		$id = (string) Settings::get( 'google_calendar_id' );
		return '' !== $id ? $id : 'primary';
	}

	/**
	 * Intervalli occupati nel calendario.
	 *
	 * @param int $from_ts Inizio finestra.
	 * @param int $to_ts   Fine finestra.
	 * @return array<int,array{0:int,1:int}>|\WP_Error
	 */
	public function busy( int $from_ts, int $to_ts ): array|\WP_Error {
		// Gli eventi "tutto il giorno" (compleanni, promemoria) non devono bloccare la giornata: si leggono gli eventi.
		if ( Settings::get( 'google_ignore_allday' ) ) {
			return $this->busy_from_event_list( $from_ts, $to_ts );
		}

		$calendar = $this->calendar_id();
		$data     = $this->request(
			'POST',
			'/freeBusy',
			array(
				'timeMin' => gmdate( 'c', $from_ts ),
				'timeMax' => gmdate( 'c', $to_ts ),
				'items'   => array( array( 'id' => $calendar ) ),
			)
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$out = array();
		foreach ( (array) ( $data['calendars'][ $calendar ]['busy'] ?? array() ) as $slot ) {
			$out[] = array( (int) strtotime( $slot['start'] ), (int) strtotime( $slot['end'] ) );
		}
		return $out;
	}

	/**
	 * Intervalli occupati letti dall'elenco degli eventi (paginato, con un tetto di sicurezza).
	 *
	 * @param int $from_ts Inizio finestra.
	 * @param int $to_ts   Fine finestra.
	 * @return array<int,array{0:int,1:int}>|\WP_Error
	 */
	private function busy_from_event_list( int $from_ts, int $to_ts ): array|\WP_Error {
		$items = array();
		$page  = '';

		// Al massimo 4 pagine da 250 eventi: oltre, meglio uno slot in più che un sito lento.
		for ( $i = 0; $i < 4; $i++ ) {
			$query = array(
				'timeMin'      => gmdate( 'c', $from_ts ),
				'timeMax'      => gmdate( 'c', $to_ts ),
				'singleEvents' => 'true',
				'maxResults'   => 250,
				'fields'       => 'nextPageToken,items(start,end,status,transparency,attendees(self,responseStatus))',
			);
			if ( '' !== $page ) {
				$query['pageToken'] = $page;
			}

			$data = $this->request( 'GET', '/calendars/' . rawurlencode( $this->calendar_id() ) . '/events?' . http_build_query( $query ) );
			if ( is_wp_error( $data ) ) {
				return $data;
			}

			$items = array_merge( $items, (array) ( $data['items'] ?? array() ) );
			$page  = (string) ( $data['nextPageToken'] ?? '' );
			if ( '' === $page ) {
				break;
			}
		}

		return self::busy_from_events( $items );
	}

	/**
	 * Trasforma gli eventi Google in intervalli occupati (funzione pura).
	 * Esclusi: eventi tutto il giorno, segnati "libero", annullati o rifiutati dall'organizzatore del calendario.
	 *
	 * @param array<int,array<string,mixed>> $items Eventi dell'API (start, end, status, transparency, attendees).
	 * @return array<int,array{0:int,1:int}>
	 */
	public static function busy_from_events( array $items ): array {
		$out = array();
		foreach ( $items as $event ) {
			$start = $event['start']['dateTime'] ?? '';
			$end   = $event['end']['dateTime'] ?? '';
			// Gli eventi tutto il giorno hanno solo "date": nessun orario da occupare.
			if ( '' === $start || '' === $end ) {
				continue;
			}
			if ( 'cancelled' === ( $event['status'] ?? '' ) || 'transparent' === ( $event['transparency'] ?? '' ) ) {
				continue;
			}
			// Invito rifiutato dall'utente del calendario: non occupa tempo.
			foreach ( (array) ( $event['attendees'] ?? array() ) as $attendee ) {
				if ( ! empty( $attendee['self'] ) && 'declined' === ( $attendee['responseStatus'] ?? '' ) ) {
					continue 2;
				}
			}
			$out[] = array( (int) strtotime( (string) $start ), (int) strtotime( (string) $end ) );
		}
		return $out;
	}

	/**
	 * Crea l'evento (con link Meet se richiesto).
	 *
	 * @param array<string,mixed> $event Campi: summary, description, location, start_ts, end_ts, attendee_email, attendee_name, request_id.
	 * @param bool                $meet  Genera il link Google Meet.
	 * @return array{id:string,meet_url:string}|\WP_Error
	 */
	public function create_event( array $event, bool $meet ): array|\WP_Error {
		$body = array(
			'summary'     => $event['summary'],
			'description' => $event['description'],
			'location'    => $event['location'],
			'start'       => array( 'dateTime' => gmdate( 'c', (int) $event['start_ts'] ) ),
			'end'         => array( 'dateTime' => gmdate( 'c', (int) $event['end_ts'] ) ),
			'attendees'   => array(
				array(
					'email'       => $event['attendee_email'],
					'displayName' => $event['attendee_name'],
				),
			),
		);
		// Promemoria sul calendario dell'organizzatore (0 = predefiniti del calendario).
		$minutes = (int) Settings::get( 'google_reminder_minutes' );
		if ( $minutes > 0 ) {
			$body['reminders'] = array(
				'useDefault' => false,
				'overrides'  => array(
					array(
						'method'  => 'popup',
						'minutes' => $minutes,
					),
					array(
						'method'  => 'email',
						'minutes' => $minutes,
					),
				),
			);
		}
		if ( $meet ) {
			$body['conferenceData'] = array(
				'createRequest' => array(
					'requestId'             => $event['request_id'],
					'conferenceSolutionKey' => array( 'type' => 'hangoutsMeet' ),
				),
			);
		}

		// sendUpdates=none: l'invito al cliente parte dal plugin, con il suo .ics e i link di gestione.
		$data = $this->request(
			'POST',
			'/calendars/' . rawurlencode( $this->calendar_id() ) . '/events?sendUpdates=none&conferenceDataVersion=' . ( $meet ? 1 : 0 ),
			$body
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		return array(
			'id'       => (string) ( $data['id'] ?? '' ),
			'meet_url' => (string) ( $data['hangoutLink'] ?? '' ),
		);
	}

	/**
	 * Sposta un evento esistente.
	 *
	 * @param string $event_id Id evento Google.
	 * @param int    $start_ts Nuovo inizio.
	 * @param int    $end_ts   Nuova fine.
	 * @return true|\WP_Error
	 */
	public function move_event( string $event_id, int $start_ts, int $end_ts ): true|\WP_Error {
		$data = $this->request(
			'PATCH',
			'/calendars/' . rawurlencode( $this->calendar_id() ) . '/events/' . rawurlencode( $event_id ) . '?sendUpdates=none',
			array(
				'start' => array( 'dateTime' => gmdate( 'c', $start_ts ) ),
				'end'   => array( 'dateTime' => gmdate( 'c', $end_ts ) ),
			)
		);
		return is_wp_error( $data ) ? $data : true;
	}

	/**
	 * Elimina un evento.
	 *
	 * @param string $event_id Id evento Google.
	 * @return true|\WP_Error
	 */
	public function delete_event( string $event_id ): true|\WP_Error {
		$data = $this->request( 'DELETE', '/calendars/' . rawurlencode( $this->calendar_id() ) . '/events/' . rawurlencode( $event_id ) . '?sendUpdates=none' );
		return is_wp_error( $data ) ? $data : true;
	}

	/**
	 * Esegue una chiamata autenticata.
	 *
	 * @param string                   $method HTTP method.
	 * @param string                   $path   Percorso dopo /calendar/v3.
	 * @param array<string,mixed>|null $body   Corpo JSON.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function request( string $method, string $path, ?array $body = null ): array|\WP_Error {
		$token = $this->oauth->access_token();
		if ( is_wp_error( $token ) ) {
			Logger::log( $token->get_error_message() );
			return $token;
		}

		$args = array(
			'method'  => $method,
			'timeout' => 15,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( self::API . $path, $args );
		if ( is_wp_error( $response ) ) {
			Logger::log( $response->get_error_message() );
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 400 ) {
			$message = 'Google Calendar HTTP ' . $code . ': ' . wp_remote_retrieve_body( $response );
			Logger::log( $message );
			return new \WP_Error( 'wpbac_google_api', $message );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $data ) ? $data : array();
	}
}
