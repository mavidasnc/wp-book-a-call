<?php
/**
 * Operazioni su intervalli di date (logica pura, senza dipendenze da WordPress).
 *
 * @package Mavida\BookACall
 */

declare(strict_types=1);

namespace Mavida\BookACall\Availability;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Gli intervalli sono coppie [dal, al] di date Y-m-d, estremi inclusi.
 */
final class DateRanges {

	/**
	 * Aggiunge giorni a un insieme di intervalli, unendo quelli sovrapposti o contigui.
	 *
	 * @param array<int,array{0:string,1:string}> $ranges Intervalli esistenti.
	 * @param string[]                            $days   Giorni da bloccare.
	 * @return array<int,array{0:string,1:string}>
	 */
	public static function add( array $ranges, array $days ): array {
		foreach ( $days as $day ) {
			$ranges[] = array( $day, $day );
		}

		usort( $ranges, static fn( array $a, array $b ): int => strcmp( $a[0], $b[0] ) );

		$merged = array();
		foreach ( $ranges as $range ) {
			$last = count( $merged ) - 1;
			// Si unisce se il nuovo intervallo inizia al più il giorno dopo la fine del precedente.
			if ( $last >= 0 && $range[0] <= self::shift( $merged[ $last ][1], 1 ) ) {
				if ( $range[1] > $merged[ $last ][1] ) {
					$merged[ $last ][1] = $range[1];
				}
				continue;
			}
			$merged[] = $range;
		}
		return $merged;
	}

	/**
	 * Toglie giorni da un insieme di intervalli, dividendo quelli che li contengono.
	 *
	 * @param array<int,array{0:string,1:string}> $ranges Intervalli esistenti.
	 * @param string[]                            $days   Giorni da sbloccare.
	 * @return array<int,array{0:string,1:string}>
	 */
	public static function remove( array $ranges, array $days ): array {
		foreach ( $days as $day ) {
			$next = array();
			foreach ( $ranges as $range ) {
				if ( $day < $range[0] || $day > $range[1] ) {
					$next[] = $range;
					continue;
				}
				if ( $day > $range[0] ) {
					$next[] = array( $range[0], self::shift( $day, -1 ) );
				}
				if ( $day < $range[1] ) {
					$next[] = array( self::shift( $day, 1 ), $range[1] );
				}
			}
			$ranges = $next;
		}
		return array_values( $ranges );
	}

	/**
	 * Sposta una data di un numero di giorni.
	 *
	 * @param string $date Data Y-m-d.
	 * @param int    $days Giorni (anche negativi).
	 * @return string
	 */
	private static function shift( string $date, int $days ): string {
		return ( new DateTimeImmutable( $date, new DateTimeZone( 'UTC' ) ) )->modify( sprintf( '%+d day', $days ) )->format( 'Y-m-d' );
	}
}
