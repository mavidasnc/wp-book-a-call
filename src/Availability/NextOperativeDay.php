<?php
/**
 * Primo giorno operativo successivo a oggi (logica pura, senza dipendenze da WordPress).
 *
 * @package Mavida\BookACall
 */

declare(strict_types=1);

namespace Mavida\BookACall\Availability;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Serve per l'opzione "non accettare prenotazioni per il prossimo giorno utile".
 */
final class NextOperativeDay {

	/**
	 * Giorni della settimana indicizzati come date('N') - 1.
	 *
	 * @var string[]
	 */
	private const DAYS = array( 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' );

	/**
	 * Primo giorno dopo $today con fasce orarie e non chiuso.
	 *
	 * @param string                                            $today        Data di oggi (Y-m-d, nel fuso del sito).
	 * @param array<string,array<int,array{0:string,1:string}>> $weekly_hours Fasce per giorno della settimana.
	 * @param array<int,array{date_from:string,date_to:string}> $closed       Giorni chiusi (eccezioni, festività).
	 * @param int                                               $lookahead    Giorni da esaminare al massimo.
	 * @return string|null Data Y-m-d, null se non ce ne sono.
	 */
	public static function find( string $today, array $weekly_hours, array $closed, int $lookahead = 60 ): ?string {
		$day = new DateTimeImmutable( $today, new DateTimeZone( 'UTC' ) );

		for ( $i = 1; $i <= $lookahead; $i++ ) {
			$day  = $day->modify( '+1 day' );
			$date = $day->format( 'Y-m-d' );
			$key  = self::DAYS[ (int) $day->format( 'N' ) - 1 ];

			if ( empty( $weekly_hours[ $key ] ) || self::is_closed( $date, $closed ) ) {
				continue;
			}
			return $date;
		}
		return null;
	}

	/**
	 * La data cade in uno dei giorni chiusi?
	 *
	 * @param string                                            $date   Data Y-m-d.
	 * @param array<int,array{date_from:string,date_to:string}> $closed Giorni chiusi.
	 * @return bool
	 */
	private static function is_closed( string $date, array $closed ): bool {
		foreach ( $closed as $range ) {
			if ( $date >= $range['date_from'] && $date <= $range['date_to'] ) {
				return true;
			}
		}
		return false;
	}
}
