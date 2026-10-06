<?php
/**
 * Endpoint di amministrazione: tipi di call, prenotazioni, eccezioni, impostazioni.
 *
 * @package Mavida\BookACall
 */

declare(strict_types=1);

namespace Mavida\BookACall\REST;

use Mavida\BookACall\Availability\AvailabilityService;
use Mavida\BookACall\Availability\ItalianHolidays;
use Mavida\BookACall\Booking\BookingService;
use Mavida\BookACall\Database\BookingRepository;
use Mavida\BookACall\Database\EventTypeRepository;
use Mavida\BookACall\Database\ExceptionRepository;
use Mavida\BookACall\Email\EmailSender;
use Mavida\BookACall\Email\ResendClient;
use Mavida\BookACall\Export\BookingExporter;
use Mavida\BookACall\Google\OAuthClient;
use Mavida\BookACall\Reminders\ReminderService;
use Mavida\BookACall\Support\Placeholders;
use Mavida\BookACall\Support\Settings;
use Mavida\BookACall\Support\Token;
use Mavida\BookACall\Webhook\WebhookSender;

defined( 'ABSPATH' ) || exit;

/**
 * Tutte le rotte richiedono manage_options (cookie + nonce wp_rest).
 */
final class AdminController extends RestController {

	/**
	 * Costruttore.
	 *
	 * @param EventTypeRepository $types      Tipi di call.
	 * @param BookingRepository   $bookings   Prenotazioni.
	 * @param ExceptionRepository $exceptions Eccezioni.
	 * @param BookingService      $service    Servizio prenotazioni.
	 * @param EmailSender         $emails     Email.
	 * @param OAuthClient         $oauth      OAuth Google.
	 * @param WebhookSender       $webhook    Webhook.
	 * @param AvailabilityService $availability Disponibilità (spostamento delle prenotazioni).
	 * @param ResendClient        $resend     Client Resend (stato e prova della chiave).
	 */
	public function __construct(
		private readonly EventTypeRepository $types,
		private readonly BookingRepository $bookings,
		private readonly ExceptionRepository $exceptions,
		private readonly BookingService $service,
		private readonly EmailSender $emails,
		private readonly OAuthClient $oauth,
		private readonly WebhookSender $webhook,
		private readonly AvailabilityService $availability,
		private readonly ResendClient $resend
	) {}

	/**
	 * Registra le rotte.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$admin = array( $this, 'check_admin_permission' );

		register_rest_route(
			self::API_NAMESPACE,
			'/admin/event-types',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => fn() => $this->respond( $this->types->all() ),
					'permission_callback' => $admin,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'save_event_type' ),
					'permission_callback' => $admin,
				),
			)
		);
		register_rest_route(
			self::API_NAMESPACE,
			'/admin/event-types/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'save_event_type' ),
					'permission_callback' => $admin,
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'delete_event_type' ),
					'permission_callback' => $admin,
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/admin/bookings',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_bookings' ),
				'permission_callback' => $admin,
			)
		);
		register_rest_route(
			self::API_NAMESPACE,
			'/admin/bookings/export',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'export_bookings' ),
				'permission_callback' => $admin,
			)
		);
		register_rest_route(
			self::API_NAMESPACE,
			'/admin/bookings/(?P<id>\d+)/slots',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'booking_slots' ),
				'permission_callback' => $admin,
			)
		);
		register_rest_route(
			self::API_NAMESPACE,
			'/admin/bookings/(?P<id>\d+)/reschedule',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'reschedule_booking' ),
				'permission_callback' => $admin,
			)
		);
		register_rest_route(
			self::API_NAMESPACE,
			'/admin/holidays',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'holidays' ),
				'permission_callback' => $admin,
			)
		);
		register_rest_route(
			self::API_NAMESPACE,
			'/admin/bookings/(?P<id>\d+)/cancel',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'cancel_booking' ),
				'permission_callback' => $admin,
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/admin/exceptions',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => fn() => $this->respond( $this->exceptions->all() ),
					'permission_callback' => $admin,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_exception' ),
					'permission_callback' => $admin,
				),
			)
		);
		register_rest_route(
			self::API_NAMESPACE,
			'/admin/exceptions/toggle',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'toggle_exceptions' ),
				'permission_callback' => $admin,
			)
		);
		register_rest_route(
			self::API_NAMESPACE,
			'/admin/exceptions/(?P<id>\d+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'delete_exception' ),
				'permission_callback' => $admin,
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/admin/settings',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => fn() => $this->respond( $this->settings_view() ),
					'permission_callback' => $admin,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'save_settings' ),
					'permission_callback' => $admin,
				),
			)
		);
		register_rest_route(
			self::API_NAMESPACE,
			'/admin/settings/test-email',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'send_test_email' ),
				'permission_callback' => $admin,
			)
		);
		register_rest_route(
			self::API_NAMESPACE,
			'/admin/settings/test-webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'send_test_webhook' ),
				'permission_callback' => $admin,
			)
		);
		register_rest_route(
			self::API_NAMESPACE,
			'/admin/resend/test',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'retry_resend' ),
				'permission_callback' => $admin,
			)
		);
		register_rest_route(
			self::API_NAMESPACE,
			'/admin/resend/remove',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'remove_resend' ),
				'permission_callback' => $admin,
			)
		);
		register_rest_route(
			self::API_NAMESPACE,
			'/admin/google/disconnect',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'disconnect_google' ),
				'permission_callback' => $admin,
			)
		);
	}

	/**
	 * Crea o aggiorna un tipo di call.
	 *
	 * @param \WP_REST_Request $request Richiesta.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function save_event_type( \WP_REST_Request $request ) {
		$id    = (int) $request['id'];
		$saved = $this->types->save( (array) $request->get_json_params(), $id );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		return $this->respond( $this->types->find( $saved ), $id > 0 ? 200 : 201 );
	}

	/**
	 * Elimina un tipo di call, solo se non ha prenotazioni.
	 *
	 * @param \WP_REST_Request $request Richiesta.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_event_type( \WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( $this->bookings->list( 'all', $id ) ) {
			return new \WP_Error( 'wpbac_in_use', __( 'Ci sono prenotazioni collegate: disattiva il tipo di call invece di eliminarlo.', 'wp-book-a-call' ), array( 'status' => 409 ) );
		}
		$this->types->delete( $id );
		return $this->respond( array( 'deleted' => true ) );
	}

	/**
	 * Elenco prenotazioni.
	 *
	 * @param \WP_REST_Request $request Richiesta.
	 * @return \WP_REST_Response
	 */
	public function list_bookings( \WP_REST_Request $request ): \WP_REST_Response {
		$scope = in_array( $request->get_param( 'scope' ), array( 'upcoming', 'past', 'all' ), true ) ? $request->get_param( 'scope' ) : 'upcoming';
		$rows  = $this->bookings->list( $scope, (int) $request->get_param( 'event_type_id' ) );
		// Il token non va mai esposto.
		foreach ( $rows as &$row ) {
			unset( $row['token_hash'], $row['ip_hash'] );
		}
		return $this->respond( $rows );
	}

	/**
	 * Orari liberi e occupati per spostare una prenotazione (esclude la prenotazione stessa).
	 *
	 * @param \WP_REST_Request $request Richiesta (from, to: Y-m-d nel fuso del sito).
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function booking_slots( \WP_REST_Request $request ) {
		$booking = $this->bookings->find( (int) $request['id'] );
		$type    = $booking ? $this->types->find( $booking['event_type_id'] ) : null;
		if ( ! $booking || ! $type ) {
			return new \WP_Error( 'wpbac_not_found', __( 'Prenotazione non trovata.', 'wp-book-a-call' ), array( 'status' => 404 ) );
		}

		$tz   = wp_timezone();
		$from = (string) $request->get_param( 'from' );
		$to   = (string) $request->get_param( 'to' );
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ) {
			return new \WP_Error( 'wpbac_invalid', __( 'Date non valide.', 'wp-book-a-call' ), array( 'status' => 400 ) );
		}
		$from_ts = ( new \DateTimeImmutable( $from . ' 00:00', $tz ) )->getTimestamp();
		$to_ts   = min( ( new \DateTimeImmutable( $to . ' 00:00', $tz ) )->modify( '+1 day' )->getTimestamp(), $from_ts + 70 * DAY_IN_SECONDS );

		$result = $this->availability->slots_with_taken( $type, $from_ts, $to_ts, $booking['id'], true );
		return $this->respond(
			array(
				'available'     => $result['available'],
				'taken'         => $result['taken'],
				'notify_client' => $this->client_is_notified( $booking ),
				'start_ts'      => $booking['start_ts'],
			)
		);
	}

	/**
	 * Sposta una prenotazione a un altro orario e avvisa il cliente (e gli amministratori) via email.
	 *
	 * @param \WP_REST_Request $request Richiesta (corpo JSON: start).
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function reschedule_booking( \WP_REST_Request $request ) {
		$booking = $this->bookings->find( (int) $request['id'] );
		$type    = $booking ? $this->types->find( $booking['event_type_id'] ) : null;
		if ( ! $booking || ! $type ) {
			return new \WP_Error( 'wpbac_not_found', __( 'Prenotazione non trovata.', 'wp-book-a-call' ), array( 'status' => 404 ) );
		}

		$params     = (array) $request->get_json_params();
		$manage_url = $this->service->manage_url( $booking, Token::for_booking( $booking['id'] ) );
		$updated    = $this->service->reschedule( $booking, $type, (int) ( $params['start'] ?? 0 ), $manage_url );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		return $this->respond(
			array(
				'rescheduled'     => true,
				'start_ts'        => $updated['start_ts'],
				'client_notified' => $this->client_is_notified( $updated ),
			)
		);
	}

	/**
	 * Festività italiane che il plugin considera chiuse (vuoto se l'opzione è spenta).
	 *
	 * @return \WP_REST_Response
	 */
	public function holidays(): \WP_REST_Response {
		$year = (int) wp_date( 'Y' );
		return $this->respond(
			array(
				'enabled' => (bool) Settings::get( 'close_holidays' ),
				'days'    => Settings::get( 'close_holidays' ) ? ItalianHolidays::for_years( $year, $year + 2 ) : array(),
			)
		);
	}

	/**
	 * Il cliente riceve le email? (impostazione attiva e indirizzo valido)
	 *
	 * @param array<string,mixed> $booking Prenotazione.
	 * @return bool
	 */
	private function client_is_notified( array $booking ): bool {
		return (bool) Settings::get( 'client_email_enabled' ) && is_email( $booking['email'] );
	}

	/**
	 * Esporta le prenotazioni in CSV (il client crea il file e avvia il download).
	 *
	 * @param \WP_REST_Request $request Richiesta.
	 * @return \WP_REST_Response
	 */
	public function export_bookings( \WP_REST_Request $request ): \WP_REST_Response {
		$scope = in_array( $request->get_param( 'scope' ), array( 'upcoming', 'past', 'all' ), true ) ? $request->get_param( 'scope' ) : 'all';
		$rows  = $this->bookings->list( $scope, (int) $request->get_param( 'event_type_id' ), 10000 );

		return $this->respond(
			array(
				'filename' => 'prenotazioni-' . wp_date( 'Y-m-d' ) . '.csv',
				'count'    => count( $rows ),
				'csv'      => ( new BookingExporter( $this->types ) )->csv( $rows ),
			)
		);
	}

	/**
	 * Annulla una prenotazione dall'admin.
	 *
	 * @param \WP_REST_Request $request Richiesta.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function cancel_booking( \WP_REST_Request $request ) {
		$booking = $this->bookings->find( (int) $request['id'] );
		$type    = $booking ? $this->types->find( $booking['event_type_id'] ) : null;
		if ( ! $booking || ! $type ) {
			return new \WP_Error( 'wpbac_not_found', __( 'Prenotazione non trovata.', 'wp-book-a-call' ), array( 'status' => 404 ) );
		}
		$this->service->cancel( $booking, $type );
		return $this->respond(
			array(
				'cancelled'       => true,
				'client_notified' => $this->client_is_notified( $booking ),
			)
		);
	}

	/**
	 * Crea un'eccezione.
	 *
	 * @param \WP_REST_Request $request Richiesta.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_exception( \WP_REST_Request $request ) {
		$id = $this->exceptions->create( (array) $request->get_json_params() );
		return is_wp_error( $id ) ? $id : $this->respond( array( 'id' => $id ), 201 );
	}

	/**
	 * Blocca o sblocca giorni dal calendario delle eccezioni.
	 * Corpo: { dates: ["Y-m-d", ...], event_type_id: int|0, blocked: bool }.
	 *
	 * @param \WP_REST_Request $request Richiesta.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function toggle_exceptions( \WP_REST_Request $request ) {
		$params = (array) $request->get_json_params();
		$dates  = array_values( array_unique( array_filter( (array) ( $params['dates'] ?? array() ), 'is_string' ) ) );

		$valid = count( $dates ) > 0 && count( $dates ) <= 400;
		foreach ( $dates as $date ) {
			$valid = $valid && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date );
		}
		if ( ! $valid ) {
			return new \WP_Error( 'wpbac_invalid', __( 'Date non valide.', 'wp-book-a-call' ), array( 'status' => 400 ) );
		}

		$event_type_id = ! empty( $params['event_type_id'] ) ? (int) $params['event_type_id'] : null;
		$this->exceptions->toggle( $dates, $event_type_id, ! empty( $params['blocked'] ) );

		return $this->respond( $this->exceptions->all() );
	}

	/**
	 * Elimina un'eccezione.
	 *
	 * @param \WP_REST_Request $request Richiesta.
	 * @return \WP_REST_Response
	 */
	public function delete_exception( \WP_REST_Request $request ): \WP_REST_Response {
		$this->exceptions->delete( (int) $request['id'] );
		return $this->respond( array( 'deleted' => true ) );
	}

	/**
	 * Salva le impostazioni. Un segreto vuoto significa "lascia invariato".
	 *
	 * @param \WP_REST_Request $request Richiesta.
	 * @return \WP_REST_Response
	 */
	public function save_settings( \WP_REST_Request $request ): \WP_REST_Response {
		$values = (array) $request->get_json_params();
		foreach ( Settings::SECRET_KEYS as $key ) {
			if ( empty( $values[ $key ] ) ) {
				unset( $values[ $key ] );
			}
		}
		// Il refresh token si ottiene solo con il flusso OAuth o dalla CLI, non dal form.
		unset( $values['google_refresh_token'] );
		Settings::update( $values );

		$view = $this->settings_view();
		// Con una nuova chiave Resend si fa subito una prova e si riporta l'esito.
		if ( ! empty( $values['resend_api_key'] ) ) {
			$view['resend_test'] = $this->resend_test_result();
			$view                = array_merge( $view, array( 'resend' => $this->resend_view() ) );
		}
		return $this->respond( $view );
	}

	/**
	 * Riprova Resend (dopo un errore) con la chiave salvata.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function retry_resend() {
		if ( ! $this->resend->status()->has_key() ) {
			return new \WP_Error( 'wpbac_resend', __( 'Inserisci prima la chiave API di Resend.', 'wp-book-a-call' ), array( 'status' => 400 ) );
		}
		$view                = $this->settings_view();
		$view['resend_test'] = $this->resend_test_result();
		$view['resend']      = $this->resend_view();
		return $this->respond( $view );
	}

	/**
	 * Rimuove la chiave Resend: i promemoria programmati tornano a WP-Cron e le email a WordPress.
	 *
	 * @return \WP_REST_Response
	 */
	public function remove_resend(): \WP_REST_Response {
		// Prima gli invii programmati (serve ancora la chiave per annullarli), poi la chiave.
		do_action( 'wpbac_resend_disabled' );
		Settings::update( array( 'resend_api_key' => '' ) );
		$this->resend->status()->clear();
		return $this->respond( $this->settings_view() );
	}

	/**
	 * Invia la prova attraverso Resend e la riassume per l'interfaccia.
	 *
	 * @return array{ok:bool,message:string,warning?:bool}
	 */
	private function resend_test_result(): array {
		$result = $this->emails->test_resend();
		if ( is_wp_error( $result ) ) {
			return array(
				'ok'      => false,
				'message' => $result->get_error_message(),
			);
		}
		$can_cancel = $this->resend->status()->can_cancel();
		return array(
			'ok'      => true,
			'message' => $can_cancel
				? __( 'Resend funziona: ti ho mandato una email di prova.', 'wp-book-a-call' )
				: __( 'Resend funziona, ma la chiave è limitata all\'invio e non può annullare i promemoria programmati: per questo i promemoria restano gestiti da WP-Cron. Per programmarli su Resend crea una chiave con accesso completo (Full access).', 'wp-book-a-call' ),
			'warning' => ! $can_cancel,
		);
	}

	/**
	 * Stato di Resend per l'interfaccia.
	 *
	 * @return array{active:bool,can_cancel:bool,error:string,since:int}
	 */
	private function resend_view(): array {
		$state = $this->resend->status()->state();
		return array(
			'active'     => $this->resend->is_active(),
			'can_cancel' => $this->resend->status()->can_cancel(),
			'error'      => 'error' === $state['status'] ? ( '' !== $state['message'] ? $state['message'] : __( 'Errore sconosciuto.', 'wp-book-a-call' ) ) : '',
			'since'      => $state['since'],
		);
	}

	/**
	 * Invia l'email di prova.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function send_test_email() {
		if ( ! $this->emails->send_test() ) {
			return new \WP_Error( 'wpbac_mail', __( 'Invio non riuscito: controlla i destinatari e la configurazione email del sito.', 'wp-book-a-call' ), array( 'status' => 500 ) );
		}
		return $this->respond( array( 'sent' => true ) );
	}

	/**
	 * Invia al webhook un evento di esempio e riporta la risposta del server.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function send_test_webhook() {
		if ( '' === (string) Settings::get( 'webhook_url' ) ) {
			return new \WP_Error( 'wpbac_webhook', __( 'Salva prima l\'URL del webhook.', 'wp-book-a-call' ), array( 'status' => 400 ) );
		}

		$booking = array(
			'id'       => 0,
			'status'   => 'confirmed',
			'start_ts' => time() + DAY_IN_SECONDS,
			'end_ts'   => time() + DAY_IN_SECONDS + 1800,
			'name'     => 'Mario Rossi (prova)',
			'email'    => 'test@example.com',
			'timezone' => wp_timezone_string(),
			'meet_url' => '',
			'answers'  => array(),
		);
		$type    = array(
			'id'            => 0,
			'slug'          => 'prova',
			'title'         => 'Evento di prova',
			'duration_min'  => 30,
			'location_type' => 'meet',
			'questions'     => array(),
		);

		$result = $this->webhook->dispatch( 'booking.test', $booking, $type, true );
		if ( is_wp_error( $result ) ) {
			return new \WP_Error( 'wpbac_webhook', $result->get_error_message(), array( 'status' => 502 ) );
		}
		if ( $result < 200 || $result >= 300 ) {
			/* translators: %d: codice HTTP. */
			return new \WP_Error( 'wpbac_webhook', sprintf( __( 'Il webhook ha risposto con HTTP %d.', 'wp-book-a-call' ), $result ), array( 'status' => 502 ) );
		}
		return $this->respond( array( 'status' => $result ) );
	}

	/**
	 * Scollega Google.
	 *
	 * @return \WP_REST_Response
	 */
	public function disconnect_google(): \WP_REST_Response {
		$this->oauth->disconnect();
		return $this->respond( $this->settings_view() );
	}

	/**
	 * Impostazioni per il client: segreti sostituiti da flag booleani.
	 *
	 * @return array<string,mixed>
	 */
	private function settings_view(): array {
		$settings = Settings::all();
		foreach ( Settings::SECRET_KEYS as $key ) {
			$settings[ $key . '_set' ] = '' !== (string) $settings[ $key ];
			unset( $settings[ $key ] );
		}
		$settings['admin_email']         = (string) get_option( 'admin_email' );
		$settings['google_connected']    = Settings::google_connected();
		$settings['google_redirect_uri'] = $this->oauth->redirect_uri();
		$settings['google_auth_url']     = '' !== (string) Settings::get( 'google_client_id' ) && '' !== (string) Settings::get( 'google_client_secret' ) ? $this->oauth->auth_url() : '';
		$settings['resend']              = $this->resend_view();
		$settings['email_placeholders']  = Placeholders::EMAIL;
		$settings['default_privacy']     = Settings::default_privacy_text();
		// Stato del WP-Cron dei promemoria: ultimo giro e se il cron di WordPress è disattivato.
		$settings['reminders_last_run'] = (int) get_option( ReminderService::LAST_RUN_OPTION, 0 );
		$settings['wp_cron_disabled']   = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		return $settings;
	}
}
