<?php
/**
 * Programmazione dei promemoria su Resend (più affidabile di WP-Cron).
 *
 * @package Mavida\BookACall
 */

declare(strict_types=1);

namespace Mavida\BookACall\Reminders;

use Mavida\BookACall\Booking\BookingService;
use Mavida\BookACall\Database\BookingRepository;
use Mavida\BookACall\Database\EventTypeRepository;
use Mavida\BookACall\Email\EmailSender;
use Mavida\BookACall\Email\ResendClient;
use Mavida\BookACall\Support\Settings;
use Mavida\BookACall\Support\Token;

defined( 'ABSPATH' ) || exit;

/**
 * Con Resend attivo ogni promemoria viene programmato (scheduled_at) appena la prenotazione nasce;
 * senza Resend, o oltre i 30 giorni, resta il controllo di WP-Cron in ReminderService.
 * Stato del promemoria: 0 da fare (WP-Cron), 1 inviato o non necessario, 2 programmato su Resend.
 */
final class ReminderScheduler {

	/**
	 * Costruttore.
	 *
	 * @param BookingRepository   $bookings Prenotazioni.
	 * @param EventTypeRepository $types    Tipi di call.
	 * @param EmailSender         $emails   Email.
	 * @param ResendClient        $resend   Client Resend.
	 * @param BookingService      $service  Servizio prenotazioni (per il link di gestione).
	 */
	public function __construct(
		private readonly BookingRepository $bookings,
		private readonly EventTypeRepository $types,
		private readonly EmailSender $emails,
		private readonly ResendClient $resend,
		private readonly BookingService $service
	) {}

	/**
	 * Aggancia le azioni delle prenotazioni e la disattivazione di Resend.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'wpbac_booking_created', array( $this, 'on_created' ), 10, 2 );
		add_action( 'wpbac_booking_rescheduled', array( $this, 'on_rescheduled' ), 10, 2 );
		add_action( 'wpbac_booking_cancelled', array( $this, 'on_cancelled' ) );
		add_action( 'wpbac_resend_disabled', array( $this, 'revert_to_cron' ) );
	}

	/**
	 * Nuova prenotazione: programma i promemoria.
	 *
	 * @param array<string,mixed> $booking    Prenotazione.
	 * @param array<string,mixed> $event_type Tipo di call.
	 * @return void
	 */
	public function on_created( array $booking, array $event_type ): void {
		$this->schedule( $booking, $event_type );
	}

	/**
	 * Prenotazione spostata: annulla i vecchi invii e ne programma di nuovi.
	 *
	 * @param array<string,mixed> $booking    Prenotazione.
	 * @param array<string,mixed> $event_type Tipo di call.
	 * @return void
	 */
	public function on_rescheduled( array $booking, array $event_type ): void {
		$this->cancel_scheduled( $booking );
		// I flag sono stati ricalcolati dal servizio; si rilegge la riga per avere anche i riferimenti azzerati.
		$fresh = $this->bookings->find( $booking['id'] );
		if ( $fresh ) {
			$this->schedule( $fresh, $event_type );
		}
	}

	/**
	 * Prenotazione annullata: annulla gli invii programmati.
	 *
	 * @param array<string,mixed> $booking Prenotazione.
	 * @return void
	 */
	public function on_cancelled( array $booking ): void {
		$this->cancel_scheduled( $booking );
	}

	/**
	 * Programma su Resend i promemoria attivi e ancora da fare di una prenotazione.
	 *
	 * @param array<string,mixed> $booking    Prenotazione.
	 * @param array<string,mixed> $event_type Tipo di call.
	 * @return void
	 */
	public function schedule( array $booking, array $event_type ): void {
		if ( ! $this->resend->is_active() || 'confirmed' !== $booking['status'] ) {
			return;
		}

		$plan = ReminderSchedule::plan(
			(int) $booking['start_ts'],
			time(),
			(bool) Settings::get( 'reminder_24h' ),
			(bool) Settings::get( 'reminder_1h' )
		);

		foreach ( $plan as $kind => $send_ts ) {
			$kind = (string) $kind;
			// Solo i promemoria ancora da fare: quelli già inviati o programmati non si toccano.
			if ( 0 !== (int) $booking[ '1' === $kind ? 'reminder_1_sent' : 'reminder_24_sent' ] ) {
				continue;
			}
			// Se Resend è andato in errore durante il giro, i restanti restano a WP-Cron.
			if ( ! $this->resend->is_active() ) {
				return;
			}

			$message = $this->emails->prepare_reminder( $kind, $booking, $event_type, $this->service->manage_url( $booking, Token::for_booking( $booking['id'] ) ) );
			if ( null === $message ) {
				continue;
			}
			$message['scheduled_at'] = gmdate( 'Y-m-d\TH:i:s\Z', $send_ts );

			$result = $this->resend->send( $message );
			if ( ! is_wp_error( $result ) && '' !== $result['id'] ) {
				$this->bookings->set_reminder( $booking['id'], $kind, 2, $result['id'] );
			}
		}
	}

	/**
	 * Programma i promemoria che sono entrati nell'orizzonte dei 30 giorni (chiamato dal cron).
	 *
	 * @return void
	 */
	public function sync_pending(): void {
		if ( ! $this->resend->is_active() ) {
			return;
		}

		$now = time();
		foreach ( array( '24', '1' ) as $kind ) {
			if ( ! Settings::get( '24' === $kind ? 'reminder_24h' : 'reminder_1h' ) ) {
				continue;
			}
			foreach ( $this->bookings->unscheduled_reminders( $kind, $now + ReminderSchedule::MIN_LEAD, ReminderSchedule::MAX_AHEAD ) as $booking ) {
				$event_type = $this->types->find( $booking['event_type_id'] );
				if ( $event_type ) {
					$this->schedule( $booking, $event_type );
				}
			}
		}
	}

	/**
	 * Annulla su Resend gli invii programmati di una prenotazione e azzera i riferimenti.
	 * Lo stato dei promemoria non cambia: dopo uno spostamento lo ha già ricalcolato il servizio.
	 * Se l'email era già partita l'annullamento fallisce senza conseguenze.
	 *
	 * @param array<string,mixed> $booking Prenotazione.
	 * @return void
	 */
	private function cancel_scheduled( array $booking ): void {
		foreach ( array( 'reminder_24_ref', 'reminder_1_ref' ) as $column ) {
			$ref = (string) ( $booking[ $column ] ?? '' );
			if ( '' === $ref ) {
				continue;
			}
			$this->resend->cancel( $ref );
			$this->bookings->update( $booking['id'], array( $column => '' ) );
		}
	}

	/**
	 * Resend è stato disattivato: i promemoria programmati tornano sotto il controllo di WP-Cron.
	 * Quelli il cui orario di invio è già passato si considerano partiti (stato 1), per evitare doppioni.
	 *
	 * @return void
	 */
	public function revert_to_cron(): void {
		$now = time();
		foreach ( $this->bookings->with_scheduled_reminders( $now ) as $booking ) {
			$this->cancel_scheduled( $booking );
			foreach ( ReminderSchedule::OFFSETS as $kind => $offset ) {
				$kind = (string) $kind;
				if ( 2 === (int) $booking[ '1' === $kind ? 'reminder_1_sent' : 'reminder_24_sent' ] ) {
					$this->bookings->set_reminder( $booking['id'], $kind, (int) $booking['start_ts'] - $offset > $now ? 0 : 1, '' );
				}
			}
		}
	}
}
