<?php
/**
 * Servizio di disponibilità: unisce DB, eccezioni e Google Calendar.
 *
 * @package Mavida\BookACall
 */

declare(strict_types=1);

namespace Mavida\BookACall\Availability;

use Mavida\BookACall\Database\BookingRepository;
use Mavida\BookACall\Database\ExceptionRepository;
use Mavida\BookACall\Google\CalendarClient;
use Mavida\BookACall\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Calcola gli slot liberi per un tipo di call.
 */
final class AvailabilityService {

	/**
	 * Costruttore.
	 *
	 * @param BookingRepository   $bookings   Prenotazioni.
	 * @param ExceptionRepository $exceptions Eccezioni.
	 * @param CalendarClient      $calendar   Client Google.
	 * @param SlotGenerator       $generator  Generatore di slot.
	 */
	public function __construct(
		private readonly BookingRepository $bookings,
		private readonly ExceptionRepository $exceptions,
		private readonly CalendarClient $calendar,
		private readonly SlotGenerator $generator
	) {}

	/**
	 * Slot liberi in una finestra.
	 *
	 * @param array<string,mixed> $event_type Tipo di call.
	 * @param int                 $from_ts    Inizio (incluso).
	 * @param int                 $to_ts      Fine (esclusa).
	 * @param int                 $exclude_id Prenotazione da ignorare (spostamento).
	 * @param bool                $fresh      Ignora la cache del free/busy Google.
	 * @return int[]
	 */
	public function slots( array $event_type, int $from_ts, int $to_ts, int $exclude_id = 0, bool $fresh = false ): array {
		$busy = $this->bookings->busy_intervals( $from_ts - DAY_IN_SECONDS, $to_ts + DAY_IN_SECONDS, $exclude_id );
		$busy = array_merge( $busy, $this->google_busy( $from_ts - DAY_IN_SECONDS, $to_ts + DAY_IN_SECONDS, $fresh ) );

		return $this->generator->generate(
			$event_type,
			$from_ts,
			$to_ts,
			$busy,
			$this->exceptions->for_event_type( (int) $event_type['id'] ),
			time(),
			wp_timezone()
		);
	}

	/**
	 * Lo slot che inizia a $start_ts è ancora libero?
	 *
	 * @param array<string,mixed> $event_type Tipo di call.
	 * @param int                 $start_ts   Inizio richiesto.
	 * @param int                 $exclude_id Prenotazione da ignorare.
	 * @return bool
	 */
	public function is_available( array $event_type, int $start_ts, int $exclude_id = 0 ): bool {
		return in_array( $start_ts, $this->slots( $event_type, $start_ts, $start_ts + 1, $exclude_id, true ), true );
	}

	/**
	 * Intervalli occupati letti da Google (se attivo), con cache di 5 minuti.
	 *
	 * @param int  $from_ts Inizio.
	 * @param int  $to_ts   Fine.
	 * @param bool $fresh   Salta la cache.
	 * @return array<int,array{0:int,1:int}>
	 */
	private function google_busy( int $from_ts, int $to_ts, bool $fresh ): array {
		if ( ! Settings::get( 'google_use_busy' ) || ! $this->calendar->is_connected() ) {
			return array();
		}

		$key = 'wpbac_busy_' . md5( $from_ts . '-' . $to_ts );
		if ( ! $fresh ) {
			$cached = get_transient( $key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$busy = $this->calendar->busy( $from_ts, $to_ts );
		if ( is_wp_error( $busy ) ) {
			// Se Google non risponde si usa solo il DB: meglio uno slot in più che il sito bloccato.
			return array();
		}

		set_transient( $key, $busy, 5 * MINUTE_IN_SECONDS );
		return $busy;
	}
}
