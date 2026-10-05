<?php
/**
 * Generatore di file iCalendar (RFC 5545), logica pura.
 *
 * @package Mavida\BookACall
 */

declare(strict_types=1);

namespace Mavida\BookACall\Calendar;

/**
 * Costruisce un VCALENDAR con un singolo VEVENT.
 */
final class IcsBuilder {

	/**
	 * Costruisce il contenuto del file .ics.
	 *
	 * @param array<string,mixed> $e Dati: uid, start_ts, end_ts, summary, description, location, organizer_name, organizer_email, attendee_name, attendee_email, sequence, method (REQUEST|CANCEL), url.
	 * @return string
	 */
	public function build( array $e ): string {
		$method = 'CANCEL' === ( $e['method'] ?? '' ) ? 'CANCEL' : 'REQUEST';
		$lines  = array(
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//Mavida//WP Book a Call//IT',
			'CALSCALE:GREGORIAN',
			'METHOD:' . $method,
			'BEGIN:VEVENT',
			'UID:' . $e['uid'],
			'DTSTAMP:' . gmdate( 'Ymd\THis\Z' ),
			'DTSTART:' . gmdate( 'Ymd\THis\Z', (int) $e['start_ts'] ),
			'DTEND:' . gmdate( 'Ymd\THis\Z', (int) $e['end_ts'] ),
			'SEQUENCE:' . (int) ( $e['sequence'] ?? 0 ),
			'SUMMARY:' . $this->escape( (string) $e['summary'] ),
			'STATUS:' . ( 'CANCEL' === $method ? 'CANCELLED' : 'CONFIRMED' ),
		);

		if ( ! empty( $e['description'] ) ) {
			$lines[] = 'DESCRIPTION:' . $this->escape( (string) $e['description'] );
		}
		if ( ! empty( $e['location'] ) ) {
			$lines[] = 'LOCATION:' . $this->escape( (string) $e['location'] );
		}
		if ( ! empty( $e['url'] ) ) {
			$lines[] = 'URL:' . $e['url'];
		}
		if ( ! empty( $e['organizer_email'] ) ) {
			$lines[] = 'ORGANIZER;CN=' . $this->param( (string) ( $e['organizer_name'] ?? '' ) ) . ':mailto:' . $e['organizer_email'];
		}
		if ( ! empty( $e['attendee_email'] ) ) {
			$lines[] = 'ATTENDEE;CN=' . $this->param( (string) ( $e['attendee_name'] ?? '' ) ) . ';ROLE=REQ-PARTICIPANT;PARTSTAT=ACCEPTED:mailto:' . $e['attendee_email'];
		}

		$lines[] = 'END:VEVENT';
		$lines[] = 'END:VCALENDAR';

		return implode( "\r\n", array_map( array( $this, 'fold' ), $lines ) ) . "\r\n";
	}

	/**
	 * Escape dei testi (backslash, punto e virgola, virgola, a capo).
	 *
	 * @param string $text Testo.
	 * @return string
	 */
	private function escape( string $text ): string {
		$text = str_replace( array( '\\', ';', ',' ), array( '\\\\', '\;', '\,' ), $text );
		return str_replace( array( "\r\n", "\r", "\n" ), '\n', $text );
	}

	/**
	 * Valore di parametro tra virgolette (senza virgolette interne).
	 *
	 * @param string $text Testo.
	 * @return string
	 */
	private function param( string $text ): string {
		return '"' . str_replace( array( '"', "\r", "\n" ), '', $text ) . '"';
	}

	/**
	 * Piega una riga a 75 ottetti senza spezzare i caratteri UTF-8.
	 *
	 * @param string $line Riga.
	 * @return string
	 */
	private function fold( string $line ): string {
		if ( strlen( $line ) <= 75 ) {
			return $line;
		}

		$out   = '';
		$chunk = '';
		$limit = 75;
		foreach ( (array) preg_split( '//u', $line, -1, PREG_SPLIT_NO_EMPTY ) as $char ) {
			if ( strlen( $chunk ) + strlen( $char ) > $limit ) {
				$out  .= $chunk . "\r\n ";
				$chunk = '';
				$limit = 74; // La riga di continuazione inizia con uno spazio.
			}
			$chunk .= $char;
		}
		return $out . $chunk;
	}
}
