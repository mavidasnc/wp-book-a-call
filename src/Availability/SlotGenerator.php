<?php
/**
 * Generatore degli slot disponibili (logica pura, senza dipendenze da WordPress).
 *
 * @package Mavida\BookACall
 */

declare(strict_types=1);

namespace Mavida\BookACall\Availability;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Trasforma orari settimanali, eccezioni e occupazioni in una lista di slot.
 */
final class SlotGenerator {

	/**
	 * Giorni della settimana indicizzati come date('N') - 1.
	 *
	 * @var string[]
	 */
	private const DAYS = array( 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' );

	/**
	 * Genera gli slot (timestamp UNIX di inizio) con from_ts <= inizio < to_ts.
	 *
	 * @param array<string,mixed>                               $event_type Tipo di call (duration_min, slot_step_min, buffer_*, min_notice_hours, max_days_ahead, weekly_hours).
	 * @param int                                               $from_ts    Inizio finestra (incluso).
	 * @param int                                               $to_ts      Fine finestra (esclusa).
	 * @param array<int,array{0:int,1:int}>                     $busy       Intervalli occupati [inizio, fine) in timestamp.
	 * @param array<int,array{date_from:string,date_to:string}> $exceptions Giorni di chiusura (date locali Y-m-d).
	 * @param int                                               $now        Timestamp corrente.
	 * @param DateTimeZone                                      $tz         Fuso in cui sono espressi gli orari settimanali.
	 * @param array<string,int>                                 $day_counts Prenotazioni già presenti per data locale (Y-m-d).
	 * @param int                                               $max_per_day Massimo di prenotazioni al giorno (0 = nessun limite).
	 * @return int[]
	 */
	public function generate( array $event_type, int $from_ts, int $to_ts, array $busy, array $exceptions, int $now, DateTimeZone $tz, array $day_counts = array(), int $max_per_day = 0 ): array {
		$duration = (int) $event_type['duration_min'] * 60;
		$step     = max( 1, (int) $event_type['slot_step_min'] ) * 60;
		$before   = (int) $event_type['buffer_before_min'] * 60;
		$after    = (int) $event_type['buffer_after_min'] * 60;
		$earliest = $now + (int) $event_type['min_notice_hours'] * 3600;
		$latest   = $now + (int) $event_type['max_days_ahead'] * 86400;
		$hours    = (array) $event_type['weekly_hours'];

		$slots = array();
		$day   = ( new DateTimeImmutable( '@' . $from_ts ) )->setTimezone( $tz )->setTime( 0, 0 );
		$last  = ( new DateTimeImmutable( '@' . ( $to_ts - 1 ) ) )->setTimezone( $tz )->format( 'Y-m-d' );

		// Un giorno locale alla volta: così l'ora legale è gestita dal fuso.
		while ( $day->format( 'Y-m-d' ) <= $last ) {
			$date = $day->format( 'Y-m-d' );

			// Giorno chiuso (eccezione) oppure già al limite di prenotazioni: nessuno slot.
			$is_full = $max_per_day > 0 && ( $day_counts[ $date ] ?? 0 ) >= $max_per_day;
			if ( ! $is_full && ! $this->is_closed( $date, $exceptions ) ) {
				$key = self::DAYS[ (int) $day->format( 'N' ) - 1 ];
				foreach ( (array) ( $hours[ $key ] ?? array() ) as $range ) {
					$window_start = ( new DateTimeImmutable( $date . ' ' . $range[0], $tz ) )->getTimestamp();
					$window_end   = ( new DateTimeImmutable( $date . ' ' . $range[1], $tz ) )->getTimestamp();

					for ( $start = $window_start; $start + $duration <= $window_end; $start += $step ) {
						$end = $start + $duration;
						if ( $start < $from_ts || $start >= $to_ts || $start < $earliest || $start > $latest ) {
							continue;
						}
						if ( $this->overlaps_any( $start - $before, $end + $after, $busy ) ) {
							continue;
						}
						$slots[ $start ] = $start;
					}
				}
			}

			$day = $day->modify( '+1 day' )->setTime( 0, 0 );
		}

		ksort( $slots );
		return array_values( $slots );
	}

	/**
	 * La data cade in un'eccezione?
	 *
	 * @param string                                            $date       Data Y-m-d.
	 * @param array<int,array{date_from:string,date_to:string}> $exceptions Eccezioni.
	 * @return bool
	 */
	private function is_closed( string $date, array $exceptions ): bool {
		foreach ( $exceptions as $exception ) {
			if ( $date >= $exception['date_from'] && $date <= $exception['date_to'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * L'intervallo [start, end) si sovrappone a uno degli occupati?
	 *
	 * @param int                           $start Inizio.
	 * @param int                           $end   Fine.
	 * @param array<int,array{0:int,1:int}> $busy  Occupati.
	 * @return bool
	 */
	private function overlaps_any( int $start, int $end, array $busy ): bool {
		foreach ( $busy as $interval ) {
			if ( $start < $interval[1] && $end > $interval[0] ) {
				return true;
			}
		}
		return false;
	}
}
