<?php
/**
 * Repository delle eccezioni (giorni di chiusura).
 *
 * @package Mavida\BookACall
 */

declare(strict_types=1);

namespace Mavida\BookACall\Database;

use Mavida\BookACall\Availability\DateRanges;

// Accesso diretto alle tabelle custom del plugin: nessuna cache applicabile.
// phpcs:disable WordPress.DB.DirectDatabaseQuery
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

defined( 'ABSPATH' ) || exit;

/**
 * Intervalli di date in cui non si accettano prenotazioni.
 */
final class ExceptionRepository {

	/**
	 * Tutte le eccezioni.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function all(): array {
		global $wpdb;
		$table = Schema::table( 'exceptions' );
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY date_from ASC", ARRAY_A );
		return array_map(
			static function ( array $row ): array {
				$row['id']            = (int) $row['id'];
				$row['event_type_id'] = null === $row['event_type_id'] ? null : (int) $row['event_type_id'];
				return $row;
			},
			$rows ? $rows : array()
		);
	}

	/**
	 * Eccezioni valide per un tipo di call (globali + specifiche).
	 *
	 * @param int $event_type_id Id del tipo di call.
	 * @return array<int,array{date_from:string,date_to:string}>
	 */
	public function for_event_type( int $event_type_id ): array {
		return array_values(
			array_filter(
				$this->all(),
				static fn( array $row ): bool => null === $row['event_type_id'] || $row['event_type_id'] === $event_type_id
			)
		);
	}

	/**
	 * Crea un'eccezione.
	 *
	 * @param array<string,mixed> $data Dati grezzi.
	 * @return int|\WP_Error
	 */
	public function create( array $data ): int|\WP_Error {
		global $wpdb;

		$from = (string) ( $data['date_from'] ?? '' );
		$to   = (string) ( $data['date_to'] ?? $from );
		$ok   = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) && $from <= $to;
		if ( ! $ok ) {
			return new \WP_Error( 'wpbac_invalid', __( 'Intervallo di date non valido.', 'wp-book-a-call' ), array( 'status' => 400 ) );
		}

		$event_type_id = ! empty( $data['event_type_id'] ) ? (int) $data['event_type_id'] : null;

		$wpdb->insert(
			Schema::table( 'exceptions' ),
			array(
				'event_type_id' => $event_type_id,
				'date_from'     => $from,
				'date_to'       => $to,
				'note'          => sanitize_text_field( (string) ( $data['note'] ?? '' ) ),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Blocca o sblocca dei giorni per un ambito (tutti i tipi di call o uno specifico).
	 * Gli intervalli dell'ambito vengono ricalcolati (uniti o divisi) e riscritti.
	 *
	 * @param string[] $days          Giorni Y-m-d.
	 * @param int|null $event_type_id Tipo di call, null per tutti.
	 * @param bool     $blocked       True per bloccare, false per sbloccare.
	 * @return void
	 */
	public function toggle( array $days, ?int $event_type_id, bool $blocked ): void {
		global $wpdb;

		$current = array();
		foreach ( $this->all() as $row ) {
			if ( $row['event_type_id'] === $event_type_id ) {
				$current[] = array( $row['date_from'], $row['date_to'] );
			}
		}

		$ranges = $blocked ? DateRanges::add( $current, $days ) : DateRanges::remove( $current, $days );

		// Si riscrive l'intero ambito: più semplice e sempre coerente.
		$table = Schema::table( 'exceptions' );
		if ( null === $event_type_id ) {
			$wpdb->query( "DELETE FROM {$table} WHERE event_type_id IS NULL" );
		} else {
			$wpdb->delete( $table, array( 'event_type_id' => $event_type_id ), array( '%d' ) );
		}
		foreach ( $ranges as $range ) {
			$wpdb->insert(
				$table,
				array(
					'event_type_id' => $event_type_id,
					'date_from'     => $range[0],
					'date_to'       => $range[1],
					'note'          => '',
				)
			);
		}
	}

	/**
	 * Elimina un'eccezione.
	 *
	 * @param int $id Id.
	 * @return void
	 */
	public function delete( int $id ): void {
		global $wpdb;
		$wpdb->delete( Schema::table( 'exceptions' ), array( 'id' => $id ), array( '%d' ) );
	}
}
