<?php
/**
 * Logica di creazione, spostamento e annullamento delle prenotazioni.
 *
 * @package Mavida\BookACall
 */

declare(strict_types=1);

namespace Mavida\BookACall\Booking;

use DateTimeZone;
use Mavida\BookACall\Availability\AvailabilityService;
use Mavida\BookACall\Database\BookingRepository;
use Mavida\BookACall\Domain\BookingStatus;
use Mavida\BookACall\Email\EmailSender;
use Mavida\BookACall\Google\CalendarClient;
use Mavida\BookACall\Support\Logger;
use Mavida\BookACall\Support\RateLimiter;
use Mavida\BookACall\Support\Settings;
use Mavida\BookACall\Support\Token;

defined( 'ABSPATH' ) || exit;

/**
 * Orchestrazione di DB, Google ed email per il ciclo di vita di una prenotazione.
 */
final class BookingService {

	/**
	 * Costruttore.
	 *
	 * @param BookingRepository   $bookings     Prenotazioni.
	 * @param AvailabilityService $availability Disponibilità.
	 * @param CalendarClient      $calendar     Google Calendar.
	 * @param EmailSender         $emails       Email.
	 */
	public function __construct(
		private readonly BookingRepository $bookings,
		private readonly AvailabilityService $availability,
		private readonly CalendarClient $calendar,
		private readonly EmailSender $emails
	) {}

	/**
	 * Crea una prenotazione.
	 *
	 * @param array<string,mixed> $event_type Tipo di call.
	 * @param array<string,mixed> $input      Dati: start_ts, name, email, timezone, answers, source_url.
	 * @return array{booking:array<string,mixed>,manage_url:string}|\WP_Error
	 */
	public function create( array $event_type, array $input ): array|\WP_Error {
		$name  = sanitize_text_field( (string) ( $input['name'] ?? '' ) );
		$email = sanitize_email( (string) ( $input['email'] ?? '' ) );
		if ( '' === $name || ! is_email( $email ) ) {
			return new \WP_Error( 'wpbac_invalid', __( 'Nome o email non validi.', 'wp-book-a-call' ), array( 'status' => 400 ) );
		}

		// Risposte alle domande: tutte le obbligatorie devono essere presenti.
		$answers = array();
		foreach ( (array) $event_type['questions'] as $question ) {
			$value = sanitize_textarea_field( (string) ( $input['answers'][ $question['id'] ] ?? '' ) );
			if ( $question['required'] && '' === $value ) {
				/* translators: %s: testo della domanda. */
				return new \WP_Error( 'wpbac_invalid', sprintf( __( 'Campo obbligatorio: %s', 'wp-book-a-call' ), $question['label'] ), array( 'status' => 400 ) );
			}
			$answers[ $question['id'] ] = mb_substr( $value, 0, 2000 );
		}

		$start = (int) ( $input['start_ts'] ?? 0 );
		$end   = $start + $event_type['duration_min'] * 60;

		// Il lock serializza controllo e inserimento: due richieste sullo stesso slot non passano entrambe.
		if ( ! $this->bookings->acquire_lock() ) {
			return new \WP_Error( 'wpbac_busy', __( 'Il sistema è occupato, riprova tra un istante.', 'wp-book-a-call' ), array( 'status' => 503 ) );
		}
		try {
			if ( ! $this->availability->is_available( $event_type, $start ) ) {
				return new \WP_Error( 'wpbac_slot_taken', __( 'Questo orario non è più disponibile. Scegline un altro.', 'wp-book-a-call' ), array( 'status' => 409 ) );
			}
			$id = $this->bookings->insert(
				array(
					'event_type_id' => $event_type['id'],
					'start_ts'      => $start,
					'end_ts'        => $end,
					'status'        => BookingStatus::Confirmed->value,
					'name'          => $name,
					'email'         => $email,
					'timezone'      => $this->valid_timezone( (string) ( $input['timezone'] ?? '' ) ),
					'answers'       => $answers,
					'token_hash'    => '', // Si calcola dopo, quando l'id è noto.
					'source_url'    => $this->safe_url( (string) ( $input['source_url'] ?? '' ) ),
					'ip_hash'       => RateLimiter::client_hash(),
				) + $this->reminder_flags( $start )
			);
		} finally {
			$this->bookings->release_lock();
		}

		// Il token è derivato dall'id: serve anche ai promemoria, che non lo trovano in chiaro nel database.
		$token = Token::for_booking( $id );
		$this->bookings->update( $id, array( 'token_hash' => Token::hash( $token ) ) );

		// Evento Google (con Meet): se fallisce la prenotazione resta valida.
		if ( $this->calendar->is_connected() ) {
			$booking = $this->bookings->find( $id );
			$created = $this->calendar->create_event(
				array(
					'summary'        => $event_type['title'] . ' - ' . $name,
					'description'    => $this->description( $event_type, $answers ),
					'location'       => (string) $event_type['location_value'],
					'start_ts'       => $start,
					'end_ts'         => $end,
					'attendee_email' => $email,
					'attendee_name'  => $name,
					'request_id'     => 'wpbac-' . $id . '-' . wp_generate_password( 8, false ),
				),
				(bool) Settings::get( 'google_use_meet' ) && 'meet' === $event_type['location_type']
			);
			if ( ! is_wp_error( $created ) && $booking ) {
				$this->bookings->update(
					$id,
					array(
						'google_event_id' => $created['id'],
						'meet_url'        => $created['meet_url'],
					)
				);
			}
		}

		$booking    = $this->bookings->find( $id );
		$manage_url = $this->manage_url( $booking, $token );

		$this->emails->send_booking_email( 'created', $booking, $event_type, $manage_url );

		/**
		 * Prenotazione creata.
		 *
		 * @param array $booking    Prenotazione.
		 * @param array $event_type Tipo di call.
		 */
		do_action( 'wpbac_booking_created', $booking, $event_type );

		return array(
			'booking'    => $booking,
			'manage_url' => $manage_url,
		);
	}

	/**
	 * Annulla una prenotazione.
	 *
	 * @param array<string,mixed> $booking    Prenotazione.
	 * @param array<string,mixed> $event_type Tipo di call.
	 * @return void
	 */
	public function cancel( array $booking, array $event_type ): void {
		if ( BookingStatus::Cancelled->value === $booking['status'] ) {
			return;
		}

		$this->bookings->update(
			$booking['id'],
			array(
				'status'       => BookingStatus::Cancelled->value,
				'cancelled_at' => current_time( 'mysql', true ),
				'ics_sequence' => $booking['ics_sequence'] + 1,
			)
		);

		if ( '' !== $booking['google_event_id'] && $this->calendar->is_connected() ) {
			$this->calendar->delete_event( $booking['google_event_id'] );
		}

		$booking = $this->bookings->find( $booking['id'] );
		$this->emails->send_booking_email( 'cancelled', $booking, $event_type, '' );

		/** Prenotazione annullata. */
		do_action( 'wpbac_booking_cancelled', $booking, $event_type );
	}

	/**
	 * Sposta una prenotazione a un nuovo orario.
	 *
	 * @param array<string,mixed> $booking    Prenotazione.
	 * @param array<string,mixed> $event_type Tipo di call.
	 * @param int                 $start_ts   Nuovo inizio.
	 * @param string              $manage_url Link di gestione da rimettere nell'email.
	 * @return array<string,mixed>|\WP_Error Prenotazione aggiornata.
	 */
	public function reschedule( array $booking, array $event_type, int $start_ts, string $manage_url ): array|\WP_Error {
		if ( BookingStatus::Confirmed->value !== $booking['status'] ) {
			return new \WP_Error( 'wpbac_cancelled', __( 'La prenotazione è già stata annullata.', 'wp-book-a-call' ), array( 'status' => 409 ) );
		}

		$end = $start_ts + $event_type['duration_min'] * 60;

		if ( ! $this->bookings->acquire_lock() ) {
			return new \WP_Error( 'wpbac_busy', __( 'Il sistema è occupato, riprova tra un istante.', 'wp-book-a-call' ), array( 'status' => 503 ) );
		}
		try {
			if ( ! $this->availability->is_available( $event_type, $start_ts, $booking['id'] ) ) {
				return new \WP_Error( 'wpbac_slot_taken', __( 'Questo orario non è più disponibile. Scegline un altro.', 'wp-book-a-call' ), array( 'status' => 409 ) );
			}
			$this->bookings->update(
				$booking['id'],
				array(
					'start_ts'     => $start_ts,
					'end_ts'       => $end,
					'ics_sequence' => $booking['ics_sequence'] + 1,
				) + $this->reminder_flags( $start_ts )
			);
		} finally {
			$this->bookings->release_lock();
		}

		if ( '' !== $booking['google_event_id'] && $this->calendar->is_connected() ) {
			$this->calendar->move_event( $booking['google_event_id'], $start_ts, $end );
		}

		$booking = $this->bookings->find( $booking['id'] );
		$this->emails->send_booking_email( 'rescheduled', $booking, $event_type, $manage_url );

		/** Prenotazione spostata. */
		do_action( 'wpbac_booking_rescheduled', $booking, $event_type );

		return $booking;
	}

	/**
	 * Flag "promemoria già inviato" per un orario di inizio.
	 * Se la call è troppo vicina per un promemoria utile (prenotata a meno di 24 ore o 1 ora dall'inizio,
	 * più un margine di 15 minuti) il flag parte già a 1, così non arriva subito dopo la conferma.
	 *
	 * @param int $start_ts Inizio della call.
	 * @return array{reminder_24_sent:int,reminder_1_sent:int}
	 */
	private function reminder_flags( int $start_ts ): array {
		$left = $start_ts - time();
		return array(
			'reminder_24_sent' => $left <= DAY_IN_SECONDS + 15 * MINUTE_IN_SECONDS ? 1 : 0,
			'reminder_1_sent'  => $left <= HOUR_IN_SECONDS + 15 * MINUTE_IN_SECONDS ? 1 : 0,
		);
	}

	/**
	 * Link di gestione: pagina di origine + id e token.
	 *
	 * @param array<string,mixed>|null $booking Prenotazione.
	 * @param string                   $token   Token in chiaro.
	 * @return string
	 */
	public function manage_url( ?array $booking, string $token ): string {
		if ( ! $booking ) {
			return '';
		}
		$base = '' !== $booking['source_url'] ? $booking['source_url'] : home_url( '/' );
		return add_query_arg(
			array(
				'wpbac_booking' => $booking['id'],
				'wpbac_token'   => $token,
			),
			$base
		);
	}

	/**
	 * Descrizione dell'evento Google.
	 *
	 * @param array<string,mixed>  $event_type Tipo di call.
	 * @param array<string,string> $answers    Risposte per id domanda.
	 * @return string
	 */
	private function description( array $event_type, array $answers ): string {
		$lines = array();
		foreach ( (array) $event_type['questions'] as $question ) {
			if ( '' !== ( $answers[ $question['id'] ] ?? '' ) ) {
				$lines[] = $question['label'] . ': ' . $answers[ $question['id'] ];
			}
		}
		return implode( "\n", $lines );
	}

	/**
	 * Accetta solo identificatori di fuso validi.
	 *
	 * @param string $name Identificatore IANA.
	 * @return string
	 */
	private function valid_timezone( string $name ): string {
		try {
			return ( new DateTimeZone( $name ) )->getName();
		} catch ( \Exception $e ) {
			return wp_timezone_string();
		}
	}

	/**
	 * Accetta solo URL del proprio sito (previene link di gestione verso domini esterni).
	 *
	 * @param string $url URL ricevuto dal client.
	 * @return string
	 */
	private function safe_url( string $url ): string {
		$path = strtok( $url, '?#' );
		$url  = esc_url_raw( false === $path ? '' : $path );
		return wp_parse_url( $url, PHP_URL_HOST ) === wp_parse_url( home_url(), PHP_URL_HOST ) ? $url : '';
	}
}
