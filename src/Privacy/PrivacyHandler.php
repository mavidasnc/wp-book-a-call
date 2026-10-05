<?php
/**
 * Integrazione con gli strumenti Privacy di WordPress (esporta e cancella dati personali).
 *
 * @package Mavida\BookACall
 */

declare(strict_types=1);

namespace Mavida\BookACall\Privacy;

use Mavida\BookACall\Booking\BookingService;
use Mavida\BookACall\Database\BookingRepository;
use Mavida\BookACall\Database\EventTypeRepository;
use Mavida\BookACall\Google\CalendarClient;

defined( 'ABSPATH' ) || exit;

/**
 * Strumenti > Esporta dati personali / Cancella dati personali, più il testo suggerito per la privacy policy.
 */
final class PrivacyHandler {

	/**
	 * Prenotazioni elaborate per pagina.
	 *
	 * @var int
	 */
	private const PER_PAGE = 50;

	/**
	 * Costruttore.
	 *
	 * @param BookingRepository   $bookings Prenotazioni.
	 * @param EventTypeRepository $types    Tipi di call.
	 * @param BookingService      $service  Servizio prenotazioni (annullamento).
	 * @param CalendarClient      $calendar Google Calendar (eliminazione eventi).
	 */
	public function __construct(
		private readonly BookingRepository $bookings,
		private readonly EventTypeRepository $types,
		private readonly BookingService $service,
		private readonly CalendarClient $calendar
	) {}

	/**
	 * Aggancia gli hook.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
		add_action( 'admin_init', array( $this, 'add_policy_text' ) );
	}

	/**
	 * Registra l'exporter.
	 *
	 * @param array<string,array<string,mixed>> $exporters Exporter esistenti.
	 * @return array<string,array<string,mixed>>
	 */
	public function register_exporter( array $exporters ): array {
		$exporters['wp-book-a-call'] = array(
			'exporter_friendly_name' => __( 'Prenotazioni di call', 'wp-book-a-call' ),
			'callback'               => array( $this, 'export' ),
		);
		return $exporters;
	}

	/**
	 * Registra l'eraser.
	 *
	 * @param array<string,array<string,mixed>> $erasers Eraser esistenti.
	 * @return array<string,array<string,mixed>>
	 */
	public function register_eraser( array $erasers ): array {
		$erasers['wp-book-a-call'] = array(
			'eraser_friendly_name' => __( 'Prenotazioni di call', 'wp-book-a-call' ),
			'callback'             => array( $this, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Esporta le prenotazioni di una persona.
	 *
	 * @param string $email_address Email della persona.
	 * @param int    $page          Pagina, da 1.
	 * @return array{data:array<int,array<string,mixed>>,done:bool}
	 */
	public function export( string $email_address, int $page = 1 ): array {
		$rows = $this->bookings->by_email( $email_address, $page, self::PER_PAGE );
		$data = array();

		foreach ( $rows as $booking ) {
			$type    = $this->types->find( $booking['event_type_id'] );
			$answers = array();
			foreach ( (array) ( $type['questions'] ?? array() ) as $question ) {
				if ( ! empty( $booking['answers'][ $question['id'] ] ) ) {
					$answers[] = $question['label'] . ': ' . $booking['answers'][ $question['id'] ];
				}
			}

			$data[] = array(
				'group_id'          => 'wpbac_bookings',
				'group_label'       => __( 'Prenotazioni di call', 'wp-book-a-call' ),
				'group_description' => __( 'Le call prenotate tramite il sito.', 'wp-book-a-call' ),
				'item_id'           => 'wpbac-booking-' . $booking['id'],
				'data'              => array(
					array(
						'name'  => __( 'Tipo di call', 'wp-book-a-call' ),
						'value' => $type ? $type['title'] : '#' . $booking['event_type_id'],
					),
					array(
						'name'  => __( 'Data e ora', 'wp-book-a-call' ),
						'value' => wp_date( 'Y-m-d H:i', $booking['start_ts'] ),
					),
					array(
						'name'  => __( 'Stato', 'wp-book-a-call' ),
						'value' => 'confirmed' === $booking['status'] ? __( 'Confermata', 'wp-book-a-call' ) : __( 'Annullata', 'wp-book-a-call' ),
					),
					array(
						'name'  => __( 'Nome', 'wp-book-a-call' ),
						'value' => $booking['name'],
					),
					array(
						'name'  => __( 'Email', 'wp-book-a-call' ),
						'value' => $booking['email'],
					),
					array(
						'name'  => __( 'Fuso orario', 'wp-book-a-call' ),
						'value' => $booking['timezone'],
					),
					array(
						'name'  => __( 'Risposte', 'wp-book-a-call' ),
						'value' => implode( ' | ', $answers ),
					),
					array(
						'name'  => __( 'Prenotata il (GMT)', 'wp-book-a-call' ),
						'value' => (string) ( $booking['created_at'] ?? '' ),
					),
				),
			);
		}

		return array(
			'data' => $data,
			'done' => count( $rows ) < self::PER_PAGE,
		);
	}

	/**
	 * Cancella (rende anonime) le prenotazioni di una persona.
	 * Le call future vengono annullate senza email; gli eventi su Google Calendar vengono eliminati.
	 *
	 * @param string $email_address Email della persona.
	 * @param int    $page          Pagina (ignorata: le righe elaborate non corrispondono più all'email).
	 * @return array{items_removed:bool,items_retained:bool,messages:string[],done:bool}
	 */
	public function erase( string $email_address, int $page = 1 ): array {
		unset( $page );

		// Dopo l'anonimizzazione la riga non ha più l'email: si legge sempre la prima pagina.
		$rows    = $this->bookings->by_email( $email_address, 1, self::PER_PAGE );
		$removed = 0;

		foreach ( $rows as $booking ) {
			$type = $this->types->find( $booking['event_type_id'] );

			if ( 'confirmed' === $booking['status'] && $booking['end_ts'] > time() && $type ) {
				// Annulla la call futura (elimina anche l'evento Google).
				$this->service->cancel( $booking, $type, false );
			} elseif ( '' !== $booking['google_event_id'] && $this->calendar->is_connected() ) {
				// Anche un evento passato contiene nome ed email dell'ospite.
				$this->calendar->delete_event( $booking['google_event_id'] );
			}

			$this->bookings->anonymize( $booking['id'] );
			++$removed;
		}

		return array(
			'items_removed'  => $removed > 0,
			'items_retained' => false,
			'messages'       => $removed > 0
				/* translators: %d: numero di prenotazioni. */
				? array( sprintf( _n( '%d prenotazione resa anonima.', '%d prenotazioni rese anonime.', $removed, 'wp-book-a-call' ), $removed ) )
				: array(),
			'done'           => count( $rows ) < self::PER_PAGE,
		);
	}

	/**
	 * Testo suggerito per la privacy policy.
	 *
	 * @return void
	 */
	public function add_policy_text(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$text  = '<h2>' . esc_html__( 'Prenotazione di una call', 'wp-book-a-call' ) . '</h2>';
		$text .= '<p>' . esc_html__( 'Quando prenoti una call dal sito raccogliamo nome, indirizzo email, fuso orario, le risposte alle domande del modulo e data e ora scelte. L\'indirizzo IP viene salvato solo in forma di impronta non reversibile, per limitare gli abusi.', 'wp-book-a-call' ) . '</p>';
		$text .= '<p>' . esc_html__( 'I dati servono a gestire la prenotazione: invio della conferma e dei promemoria, aggiunta dell\'evento al calendario ed eventuale creazione di un link Google Meet. Se è attiva l\'integrazione, nome ed email vengono inviati a Google Calendar; se è configurato un webhook, i dati della prenotazione vengono inviati anche al servizio indicato.', 'wp-book-a-call' ) . '</p>';
		$text .= '<p>' . esc_html__( 'Puoi chiedere in qualsiasi momento l\'esportazione o la cancellazione dei tuoi dati: le prenotazioni vengono rese anonime e gli eventi collegati eliminati dal calendario.', 'wp-book-a-call' ) . '</p>';

		wp_add_privacy_policy_content( 'WP Book a Call', $text );
	}
}
