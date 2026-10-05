<?php
/**
 * Endpoint di amministrazione: tipi di call, prenotazioni, eccezioni, impostazioni.
 *
 * @package Mavida\BookACall
 */

declare(strict_types=1);

namespace Mavida\BookACall\REST;

use Mavida\BookACall\Booking\BookingService;
use Mavida\BookACall\Database\BookingRepository;
use Mavida\BookACall\Database\EventTypeRepository;
use Mavida\BookACall\Database\ExceptionRepository;
use Mavida\BookACall\Email\EmailSender;
use Mavida\BookACall\Google\OAuthClient;
use Mavida\BookACall\Support\Settings;

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
	 */
	public function __construct(
		private readonly EventTypeRepository $types,
		private readonly BookingRepository $bookings,
		private readonly ExceptionRepository $exceptions,
		private readonly BookingService $service,
		private readonly EmailSender $emails,
		private readonly OAuthClient $oauth
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
		return $this->respond( array( 'cancelled' => true ) );
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
		return $this->respond( $this->settings_view() );
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
		$settings['google_connected']    = Settings::google_connected();
		$settings['google_redirect_uri'] = $this->oauth->redirect_uri();
		$settings['google_auth_url']     = '' !== (string) Settings::get( 'google_client_id' ) && '' !== (string) Settings::get( 'google_client_secret' ) ? $this->oauth->auth_url() : '';
		return $settings;
	}
}
