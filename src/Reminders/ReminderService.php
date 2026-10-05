<?php
/**
 * Promemoria email prima della call, eseguiti da WP-Cron.
 *
 * @package Mavida\BookACall
 */

declare(strict_types=1);

namespace Mavida\BookACall\Reminders;

use Mavida\BookACall\Booking\BookingService;
use Mavida\BookACall\Database\BookingRepository;
use Mavida\BookACall\Database\EventTypeRepository;
use Mavida\BookACall\Email\EmailSender;
use Mavida\BookACall\Support\Settings;
use Mavida\BookACall\Support\Token;

defined( 'ABSPATH' ) || exit;

/**
 * Ogni 15 minuti cerca le prenotazioni vicine e manda i promemoria attivi nelle impostazioni.
 */
final class ReminderService {

	/**
	 * Nome dell'evento cron.
	 *
	 * @var string
	 */
	public const HOOK = 'wpbac_send_reminders';

	/**
	 * Option con il timestamp dell'ultimo giro del cron.
	 *
	 * @var string
	 */
	public const LAST_RUN_OPTION = 'wpbac_reminders_last_run';

	/**
	 * Costruttore.
	 *
	 * @param BookingRepository   $bookings  Prenotazioni.
	 * @param EventTypeRepository $types     Tipi di call.
	 * @param EmailSender         $emails    Email.
	 * @param BookingService      $service   Servizio prenotazioni (per il link di gestione).
	 * @param ReminderScheduler   $scheduler Programmazione su Resend.
	 */
	public function __construct(
		private readonly BookingRepository $bookings,
		private readonly EventTypeRepository $types,
		private readonly EmailSender $emails,
		private readonly BookingService $service,
		private readonly ReminderScheduler $scheduler
	) {}

	/**
	 * Aggancia cron e intervallo personalizzato.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'cron_schedules', array( $this, 'add_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- 15 minuti: serve la precisione.
		add_action( self::HOOK, array( $this, 'run' ) );
		// Si programma anche dopo un aggiornamento del plugin, senza richiedere una riattivazione.
		add_action( 'init', array( $this, 'ensure_scheduled' ) );
	}

	/**
	 * Intervallo di 15 minuti.
	 *
	 * @param array<string,array<string,mixed>> $schedules Intervalli esistenti.
	 * @return array<string,array<string,mixed>>
	 */
	public function add_schedule( array $schedules ): array {
		$schedules['wpbac_quarter_hour'] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => __( 'Ogni 15 minuti', 'wp-book-a-call' ),
		);
		return $schedules;
	}

	/**
	 * Programma l'evento se manca.
	 *
	 * @return void
	 */
	public function ensure_scheduled(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'wpbac_quarter_hour', self::HOOK );
		}
	}

	/**
	 * Invia i promemoria dovuti.
	 *
	 * @return void
	 */
	public function run(): void {
		// Segna l'ultimo giro: la scheda Notifiche avvisa se il cron si ferma.
		update_option( self::LAST_RUN_OPTION, time(), false );

		// Con Resend attivo programma i promemoria entrati nell'orizzonte dei 30 giorni.
		$this->scheduler->sync_pending();

		$kinds = array(
			'24' => (bool) Settings::get( 'reminder_24h' ),
			'1'  => (bool) Settings::get( 'reminder_1h' ),
		);

		foreach ( $kinds as $kind => $enabled ) {
			if ( ! $enabled ) {
				continue;
			}
			foreach ( $this->bookings->due_reminders( (string) $kind, time() ) as $booking ) {
				// Si segna prima di inviare: al massimo un invio, anche se due esecuzioni del cron si sovrappongono.
				$this->bookings->mark_reminder( $booking['id'], (string) $kind );

				$event_type = $this->types->find( $booking['event_type_id'] );
				if ( ! $event_type ) {
					continue;
				}
				$manage_url = $this->service->manage_url( $booking, Token::for_booking( $booking['id'] ) );
				$this->emails->send_reminder( (string) $kind, $booking, $event_type, $manage_url );
			}
		}
	}
}
