<?php
/**
 * Generatore di file CSV (logica pura, senza dipendenze da WordPress).
 *
 * @package Mavida\BookACall
 */

declare(strict_types=1);

namespace Mavida\BookACall\Export;

/**
 * CSV con BOM UTF-8 e separatore configurabile (punto e virgola per Excel in italiano).
 */
final class CsvBuilder {

	/**
	 * Costruisce il testo CSV.
	 *
	 * @param string[]                    $header    Intestazioni delle colonne.
	 * @param array<int,array<int,mixed>> $rows      Righe di dati.
	 * @param string                      $delimiter Separatore dei campi.
	 * @return string
	 */
	public static function build( array $header, array $rows, string $delimiter = ';' ): string {
		$lines = array( self::line( $header, $delimiter ) );
		foreach ( $rows as $row ) {
			$lines[] = self::line( $row, $delimiter );
		}
		// Il BOM fa riconoscere l'UTF-8 a Excel (accenti corretti).
		return "\xEF\xBB\xBF" . implode( "\r\n", $lines ) . "\r\n";
	}

	/**
	 * Una riga di celle.
	 *
	 * @param array<int,mixed> $cells     Valori.
	 * @param string           $delimiter Separatore.
	 * @return string
	 */
	private static function line( array $cells, string $delimiter ): string {
		return implode( $delimiter, array_map( static fn( $cell ): string => self::cell( $cell, $delimiter ), $cells ) );
	}

	/**
	 * Una cella: protetta dalle formule e racchiusa tra virgolette se serve.
	 *
	 * @param mixed  $value     Valore.
	 * @param string $delimiter Separatore.
	 * @return string
	 */
	private static function cell( mixed $value, string $delimiter ): string {
		$text = is_scalar( $value ) || null === $value ? (string) $value : '';

		// Un valore che inizia con = + - @ verrebbe eseguito come formula da Excel: si neutralizza con un apice.
		if ( '' !== $text && in_array( $text[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			$text = "'" . $text;
		}

		if ( 1 === preg_match( '/["\r\n' . preg_quote( $delimiter, '/' ) . ']/', $text ) ) {
			$text = '"' . str_replace( '"', '""', $text ) . '"';
		}
		return $text;
	}
}
