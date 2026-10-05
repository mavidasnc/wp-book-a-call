<?php
/**
 * Endpoint pubblici: slot, prenotazione e gestione.
 *
 * @package Mavida\BookACall
 */

declare(strict_types=1);

namespace Mavida\BookACall\REST;

use DateTimeImmutable;
use Mavida\BookACall\Availability\AvailabilityService;
use Mavida\BookACall\Booking\BookingService;
use Mavida\BookACall\Database\BookingRepository;
use Mavida\BookACall\Database\EventTypeRepository;
use Mavida\BookACall\Domain\BookingStatus;
use Mavida\BookACall\Support\RateLimiter;
use Mavida\BookACall\Support\Token;

defined( 'ABSPATH' ) || exit;

/**
 * Gli endpoint non usano il nonce `wp_rest`: le pagine possono essere in cache e il nonce scadrebbe.
 * La protezione è affidata a honeypot, rate limit, validazione e al token dei link di gestione.
 */
final class PublicController extends RestController {

	/**
	 * Costruttore.
	 *
	 * @param EventTypeRepository $types        Tipi di call.
	 * @param BookingRepository   $bookings     Prenotazioni.
	 * @param AvailabilityService $availability Disponibilità.
	 * @param BookingService      $service      Servizio prenotazioni.
	 */
	public function __construct(
		private readonly EventTypeRepository $types,
		private readonly BookingRepository $bookings,
		private readonly AvailabilityService $availability,
		private readonly BookingService $service
	) {}

	/**
	 * Registra le rotte.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::API_NAMESPACE,
			'/event-types/(?P<slug>[a-z0-9-]+)/slots',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_slots' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'from' => array(
						'required'          => true,
						'validate_callback' => array( $this, 'is_date' ),
					),
					'to'   => array(
						'required'          => true,
						'validate_callback' => array( $this, 'is_date' ),
					),
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/bookings',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_booking' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/manage/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_booking' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/manage/(?P<id>\d+)/cancel',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'cancel_booking' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/manage/(?P<id>\d+)/reschedule',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'reschedule_booking' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Valida una data Y-m-d.
	 *
	 * @param mixed $value Valore.
	 * @return bool
	 */
	public function is_date( mixed $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value );
	}

	/**
	 * Slot liberi tra due date (nel fuso del sito), massimo 62 giorni.
	 *
	 * @param \WP_REST_Request $request Richiesta.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_slots( \WP_REST_Request $request ) {
		$event_type = $this->types->find_by_slug( (string) $request['slug'] );
		if ( ! $event_type || ! $event_type['active'] ) {
			return new \WP_Error( 'wpbac_not_found', __( 'Tipo di call non trovato.', 'wp-book-a-call' ), array( 'status' => 404 ) );
		}

		$tz   = wp_timezone();
		$from = ( new DateTimeImmutable( $request['from'] . ' 00:00', $tz ) )->getTimestamp();
		$to   = ( new DateTimeImmutable( $request['to'] . ' 00:00', $tz ) )->modify( '+1 day' )->getTimestamp();
		$to   = min( $to, $from + 62 * DAY_IN_SECONDS );
		if ( $to <= $from ) {
			return new \WP_Error( 'wpbac_invalid', __( 'Intervallo non valido.', 'wp-book-a-call' ), array( 'status' => 400 ) );
		}

		return $this->respond( array( 'slots' => $this->availability->slots( $event_type, $from, $to ) ) );
	}

	/**
	 * Crea una prenotazione.
	 *
	 * @param \WP_REST_Request $request Richiesta.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_booking( \WP_REST_Request $request ) {
		// Honeypot: un campo invisibile che gli umani non compilano.
		if ( '' !== (string) $request->get_param( 'website' ) ) {
			return new \WP_Error( 'wpbac_spam', __( 'Richiesta non valida.', 'wp-book-a-call' ), array( 'status' => 400 ) );
		}
		// Un modulo compilato in meno di 2 secondi è un bot.
		if ( (int) $request->get_param( 'elapsed_ms' ) < 2000 ) {
			return new \WP_Error( 'wpbac_spam', __( 'Richiesta non valida.', 'wp-book-a-call' ), array( 'status' => 400 ) );
		}
		if ( ! RateLimiter::hit( 'create', 5, HOUR_IN_SECONDS ) ) {
			return new \WP_Error( 'wpbac_rate_limited', __( 'Troppe richieste, riprova più tardi.', 'wp-book-a-call' ), array( 'status' => 429 ) );
		}
		if ( ! filter_var( $request->get_param( 'privacy' ), FILTER_VALIDATE_BOOLEAN ) ) {
			return new \WP_Error( 'wpbac_privacy', __( 'Devi accettare l\'informativa sulla privacy.', 'wp-book-a-call' ), array( 'status' => 400 ) );
		}

		$event_type = $this->types->find_by_slug( (string) $request->get_param( 'slug' ) );
		if ( ! $event_type || ! $event_type['active'] ) {
			return new \WP_Error( 'wpbac_not_found', __( 'Tipo di call non trovato.', 'wp-book-a-call' ), array( 'status' => 404 ) );
		}

		$result = $this->service->create(
			$event_type,
			array(
				'start_ts'   => (int) $request->get_param( 'start' ),
				'name'       => (string) $request->get_param( 'name' ),
				'email'      => (string) $request->get_param( 'email' ),
				'timezone'   => (string) $request->get_param( 'timezone' ),
				'answers'    => (array) $request->get_param( 'answers' ),
				'source_url' => (string) $request->get_param( 'page_url' ),
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->respond( $this->booking_view( $result['booking'], $event_type ) + array( 'manage_url' => $result['manage_url'] ), 201 );
	}

	/**
	 * Dettagli di una prenotazione (richiede il token).
	 *
	 * @param \WP_REST_Request $request Richiesta.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_booking( \WP_REST_Request $request ) {
		$loaded = $this->authorize( $request );
		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}
		return $this->respond( $this->booking_view( $loaded[0], $loaded[1] ) );
	}

	/**
	 * Annulla una prenotazione (richiede il token).
	 *
	 * @param \WP_REST_Request $request Richiesta.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function cancel_booking( \WP_REST_Request $request ) {
		$loaded = $this->authorize( $request );
		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}
		$this->service->cancel( $loaded[0], $loaded[1] );
		return $this->respond( $this->booking_view( $this->bookings->find( $loaded[0]['id'] ), $loaded[1] ) );
	}

	/**
	 * Sposta una prenotazione (richiede il token).
	 *
	 * @param \WP_REST_Request $request Richiesta.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function reschedule_booking( \WP_REST_Request $request ) {
		$loaded = $this->authorize( $request );
		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}

		$manage_url = $this->service->manage_url( $loaded[0], (string) $request->get_param( 'token' ) );
		$updated    = $this->service->reschedule( $loaded[0], $loaded[1], (int) $request->get_param( 'start' ), $manage_url );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}
		return $this->respond( $this->booking_view( $updated, $loaded[1] ) );
	}

	/**
	 * Carica prenotazione e tipo di call verificando il token (con rate limit anti brute-force).
	 *
	 * @param \WP_REST_Request $request Richiesta.
	 * @return array{0:array<string,mixed>,1:array<string,mixed>}|\WP_Error
	 */
	private function authorize( \WP_REST_Request $request ): array|\WP_Error {
		if ( ! RateLimiter::hit( 'manage', 30, 10 * MINUTE_IN_SECONDS ) ) {
			return new \WP_Error( 'wpbac_rate_limited', __( 'Troppe richieste, riprova più tardi.', 'wp-book-a-call' ), array( 'status' => 429 ) );
		}

		$booking = $this->bookings->find( (int) $request['id'] );
		if ( ! $booking || ! Token::verify( (string) $request->get_param( 'token' ), $booking['token_hash'] ) ) {
			return new \WP_Error( 'wpbac_forbidden', __( 'Link non valido.', 'wp-book-a-call' ), array( 'status' => 403 ) );
		}

		$event_type = $this->types->find( $booking['event_type_id'] );
		if ( ! $event_type ) {
			return new \WP_Error( 'wpbac_not_found', __( 'Tipo di call non trovato.', 'wp-book-a-call' ), array( 'status' => 404 ) );
		}
		return array( $booking, $event_type );
	}

	/**
	 * Vista pubblica di una prenotazione (senza dati interni).
	 *
	 * @param array<string,mixed>|null $booking    Prenotazione.
	 * @param array<string,mixed>      $event_type Tipo di call.
	 * @return array<string,mixed>
	 */
	private function booking_view( ?array $booking, array $event_type ): array {
		return array(
			'id'        => $booking['id'],
			'status'    => $booking['status'],
			'cancelled' => BookingStatus::Cancelled->value === $booking['status'],
			'start'     => $booking['start_ts'],
			'end'       => $booking['end_ts'],
			'name'      => $booking['name'],
			'email'     => $booking['email'],
			'timezone'  => $booking['timezone'],
			'meet_url'  => $booking['meet_url'],
			'event'     => EventTypeRepository::to_public( $event_type ),
		);
	}
}
