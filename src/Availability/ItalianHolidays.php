<?php
/**
 * Festività nazionali italiane (logica pura, senza dipendenze da WordPress).
 *
 * @package Mavida\BookACall
 */

declare(strict_types=1);

namespace Mavida\BookACall\Availability;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Elenco delle festività nazionali di un anno, Pasqua e Lunedì dell'Angelo inclusi.
 */
final class ItalianHolidays {

	/**
	 * Festività a data fissa: mese-giorno => nome.
	 *
	 * @var array<string,string>
	 */
	private const FIXED = array(
		'01-01' => 'Capodanno',
		'01-06' => 'Epifania',
		'04-25' => 'Festa della Liberazione',
		'05-01' => 'Festa dei lavoratori',
		'06-02' => 'Festa della Repubblica',
		'08-15' => 'Ferragosto',
		'11-01' => 'Ognissanti',
		'12-08' => 'Immacolata Concezione',
		'12-25' => 'Natale',
		'12-26' => 'Santo Stefano',
	);

	/**
	 * Festività dell'anno, ordinate per data.
	 *
	 * @param int $year Anno (es. 2026).
	 * @return array<string,string> Data Y-m-d => nome.
	 */
	public static function for_year( int $year ): array {
		$days = array();
		foreach ( self::FIXED as $month_day => $name ) {
			$days[ sprintf( '%04d-%s', $year, $month_day ) ] = $name;
		}

		$easter          = self::easter( $year );
		$days[ $easter ] = 'Pasqua';
		$monday          = ( new DateTimeImmutable( $easter, new DateTimeZone( 'UTC' ) ) )->modify( '+1 day' )->format( 'Y-m-d' );
		$days[ $monday ] = "Lunedì dell'Angelo";

		ksort( $days );
		return $days;
	}

	/**
	 * Festività di più anni consecutivi.
	 *
	 * @param int $from_year Primo anno.
	 * @param int $to_year   Ultimo anno (incluso).
	 * @return array<string,string> Data Y-m-d => nome.
	 */
	public static function for_years( int $from_year, int $to_year ): array {
		$days = array();
		for ( $year = $from_year; $year <= $to_year; $year++ ) {
			$days += self::for_year( $year );
		}
		return $days;
	}

	/**
	 * Domenica di Pasqua (calendario gregoriano, algoritmo di Meeus/Jones/Butcher).
	 * Non usa easter_date() perché richiede l'estensione "calendar", non sempre presente.
	 *
	 * @param int $year Anno.
	 * @return string Data Y-m-d.
	 */
	public static function easter( int $year ): string {
		$a = $year % 19;
		$b = intdiv( $year, 100 );
		$c = $year % 100;
		$d = intdiv( $b, 4 );
		$e = $b % 4;
		$f = intdiv( $b + 8, 25 );
		$g = intdiv( $b - $f + 1, 3 );
		$h = ( 19 * $a + $b - $d - $g + 15 ) % 30;
		$i = intdiv( $c, 4 );
		$k = $c % 4;
		$l = ( 32 + 2 * $e + 2 * $i - $h - $k ) % 7;
		$m = intdiv( $a + 11 * $h + 22 * $l, 451 );

		$month = intdiv( $h + $l - 7 * $m + 114, 31 );
		$day   = ( ( $h + $l - 7 * $m + 114 ) % 31 ) + 1;

		return sprintf( '%04d-%02d-%02d', $year, $month, $day );
	}
}
