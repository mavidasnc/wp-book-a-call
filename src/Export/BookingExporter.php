<?php
/**
 * Esportazione delle prenotazioni in CSV.
 *
 * @package Mavida\BookACall
 */

declare(strict_types=1);

namespace Mavida\BookACall\Export;

use Mavida\BookACall\Database\EventTypeRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Trasforma le prenotazioni in righe leggibili (date nel fuso del sito) e le passa a CsvBuilder.
 */
final class BookingExporter {

	/**
	 * Costruttore.
	 *
	 * @param EventTypeRepository $types Tipi di call (per titolo e testo delle domande).
	 */
	public function __construct( private readonly EventTypeRepository $types ) {}

	/**
	 * Testo CSV di un elenco di prenotazioni.
	 *
	 * @param array<int,array<string,mixed>> $bookings Prenotazioni.
	 * @return string
	 */
	public function csv( array $bookings ): string {
		$by_id = array();
		foreach ( $this->types->all() as $type ) {
			$by_id[ $type['id'] ] = $type;
		}

		$rows = array();
		foreach ( $bookings as $booking ) {
			$type   = $by_id[ $booking['event_type_id'] ] ?? null;
			$rows[] = array(
				$booking['id'],
				'confirmed' === $booking['status'] ? 'Confermata' : 'Annullata',
				$type ? $type['title'] : '#' . $booking['event_type_id'],
				wp_date( 'Y-m-d', $booking['start_ts'] ),
				wp_date( 'H:i', $booking['start_ts'] ),
				(int) ( ( $booking['end_ts'] - $booking['start_ts'] ) / 60 ),
				$booking['name'],
				$booking['email'],
				$booking['timezone'],
				$booking['meet_url'],
				$this->answers( $booking, $type ),
				$this->local_time( $booking['created_at'] ?? null ),
				$this->local_time( $booking['cancelled_at'] ?? null ),
				$booking['source_url'],
			);
		}

		return CsvBuilder::build(
			array( 'ID', 'Stato', 'Tipo di call', 'Data', 'Ora', 'Durata (min)', 'Nome', 'Email', 'Fuso del cliente', 'Link Meet', 'Risposte', 'Creata il', 'Annullata il', 'Pagina di origine' ),
			$rows
		);
	}

	/**
	 * Risposte come "Domanda: risposta | Domanda: risposta".
	 *
	 * @param array<string,mixed>      $booking Prenotazione.
	 * @param array<string,mixed>|null $type    Tipo di call.
	 * @return string
	 */
	private function answers( array $booking, ?array $type ): string {
		$parts = array();
		foreach ( (array) ( $type['questions'] ?? array() ) as $question ) {
			$answer = (string) ( $booking['answers'][ $question['id'] ] ?? '' );
			if ( '' !== $answer ) {
				$parts[] = $question['label'] . ': ' . $answer;
			}
		}
		return implode( ' | ', $parts );
	}

	/**
	 * Data MySQL in GMT → data e ora nel fuso del sito.
	 *
	 * @param mixed $mysql_gmt Data "Y-m-d H:i:s" (GMT).
	 * @return string
	 */
	private function local_time( mixed $mysql_gmt ): string {
		if ( empty( $mysql_gmt ) ) {
			return '';
		}
		$ts = strtotime( $mysql_gmt . ' UTC' );
		return false === $ts ? '' : wp_date( 'Y-m-d H:i', $ts );
	}
}
