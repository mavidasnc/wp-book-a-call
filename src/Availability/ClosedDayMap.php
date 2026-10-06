<?php
/**
 * Mappa dei giorni chiusi con il motivo (logica pura, senza dipendenze da WordPress).
 *
 * @package Mavida\BookACall
 */

declare(strict_types=1);

namespace Mavida\BookACall\Availability;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Per ogni giorno chiuso di una finestra indica se è una festività o una chiusura generica.
 */
final class ClosedDayMap {

	/**
	 * Giorni chiusi tra due date (estremi inclusi). La festività prevale sulla chiusura generica.
	 *
	 * @param string                                            $from       Prima data Y-m-d.
	 * @param string                                            $to         Ultima data Y-m-d.
	 * @param array<int,array{date_from:string,date_to:string}> $exceptions Giorni chiusi (eccezioni, giorno successivo).
	 * @param array<string,string>                              $holidays   Festività: data Y-m-d => nome.
	 * @return array<string,array{reason:string,label:string}>
	 */
	public static function build( string $from, string $to, array $exceptions, array $holidays ): array {
		$map    = array();
		$utc    = new DateTimeZone( 'UTC' );
		$cursor = new DateTimeImmutable( $from, $utc );
		$end    = new DateTimeImmutable( $to, $utc );

		// Un giorno alla volta, con un tetto di sicurezza sulle finestre molto lunghe.
		for ( $i = 0; $cursor <= $end && $i < 800; $i++ ) {
			$date = $cursor->format( 'Y-m-d' );

			if ( isset( $holidays[ $date ] ) ) {
				$map[ $date ] = array(
					'reason' => 'holiday',
					'label'  => $holidays[ $date ],
				);
			} elseif ( self::in_ranges( $date, $exceptions ) ) {
				$map[ $date ] = array(
					'reason' => 'closed',
					'label'  => '',
				);
			}

			$cursor = $cursor->modify( '+1 day' );
		}

		return $map;
	}

	/**
	 * La data cade in uno degli intervalli?
	 *
	 * @param string                                            $date   Data Y-m-d.
	 * @param array<int,array{date_from:string,date_to:string}> $ranges Intervalli.
	 * @return bool
	 */
	private static function in_ranges( string $date, array $ranges ): bool {
		foreach ( $ranges as $range ) {
			if ( $date >= $range['date_from'] && $date <= $range['date_to'] ) {
				return true;
			}
		}
		return false;
	}
}
