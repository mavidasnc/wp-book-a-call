<?php
/**
 * Segnaposto nei testi personalizzabili (logica pura, senza dipendenze da WordPress).
 *
 * @package Mavida\BookACall
 */

declare(strict_types=1);

namespace Mavida\BookACall\Support;

/**
 * Sostituisce {name}, {date}, ... con i valori della prenotazione.
 */
final class Placeholders {

	/**
	 * Segnaposto disponibili nei messaggi email, con la loro descrizione (mostrata nell'admin).
	 *
	 * @var array<string,string>
	 */
	public const EMAIL = array(
		'name'       => 'Nome del cliente',
		'email'      => 'Email del cliente',
		'date'       => 'Data della call (es. giovedì 8 ottobre 2026)',
		'time'       => 'Ora della call (es. 10:00)',
		'datetime'   => 'Data e ora insieme',
		'timezone'   => 'Fuso orario del cliente',
		'event'      => 'Titolo del tipo di call',
		'duration'   => 'Durata in minuti',
		'host'       => "Nome dell'organizzatore",
		'meet_url'   => 'Link Google Meet (se presente)',
		'manage_url' => 'Link per spostare o annullare',
		'site'       => 'Nome del sito',
	);

	/**
	 * Sostituisce i segnaposto noti; quelli sconosciuti restano come scritti.
	 *
	 * @param string               $text   Testo con segnaposto tra graffe.
	 * @param array<string,string> $values Valori: chiave senza graffe => testo.
	 * @return string
	 */
	public static function replace( string $text, array $values ): string {
		$map = array();
		foreach ( $values as $key => $value ) {
			$map[ '{' . $key . '}' ] = $value;
		}
		return strtr( $text, $map );
	}
}
