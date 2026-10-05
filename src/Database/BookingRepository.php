<?php
/**
 * Repository delle prenotazioni.
 *
 * @package Mavida\BookACall
 */

declare(strict_types=1);

namespace Mavida\BookACall\Database;

use Mavida\BookACall\Domain\BookingStatus;

// Accesso diretto alle tabelle custom del plugin: nessuna cache applicabile.
// phpcs:disable WordPress.DB.DirectDatabaseQuery
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

defined( 'ABSPATH' ) || exit;

/**
 * Accesso alle prenotazioni. Gli orari sono timestamp UNIX UTC.
 */
final class BookingRepository {

	/**
	 * Nome del lock globale che serializza le prenotazioni.
	 *
	 * @var string
	 */
	private const LOCK_NAME = 'wpbac_booking_lock';

	/**
	 * Prende il lock applicativo (evita doppie prenotazioni concorrenti).
	 *
	 * @return bool
	 */
	public function acquire_lock(): bool {
		global $wpdb;
		return 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $wpdb->prefix . self::LOCK_NAME ) );
	}

	/**
	 * Rilascia il lock.
	 *
	 * @return void
	 */
	public function release_lock(): void {
		global $wpdb;
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $wpdb->prefix . self::LOCK_NAME ) );
	}

	/**
	 * Inserisce una prenotazione.
	 *
	 * @param array<string,mixed> $data Colonne da salvare.
	 * @return int Id.
	 */
	public function insert( array $data ): int {
		global $wpdb;
		$data['created_at'] = current_time( 'mysql', true );
		if ( isset( $data['answers'] ) && is_array( $data['answers'] ) ) {
			$data['answers'] = wp_json_encode( $data['answers'] );
		}
		$wpdb->insert( Schema::table( 'bookings' ), $data );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Aggiorna colonne di una prenotazione.
	 *
	 * @param int                 $id   Id.
	 * @param array<string,mixed> $data Colonne da aggiornare.
	 * @return void
	 */
	public function update( int $id, array $data ): void {
		global $wpdb;
		$wpdb->update( Schema::table( 'bookings' ), $data, array( 'id' => $id ) );
	}

	/**
	 * Prenotazione per id.
	 *
	 * @param int $id Id.
	 * @return array<string,mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;
		$table = Schema::table( 'bookings' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Elenco per l'admin.
	 *
	 * @param string $scope         upcoming|past|all.
	 * @param int    $event_type_id Filtro tipo di call (0 = tutti).
	 * @return array<int,array<string,mixed>>
	 */
	public function list( string $scope = 'upcoming', int $event_type_id = 0 ): array {
		global $wpdb;
		$table = Schema::table( 'bookings' );
		$where = array( '1=1' );
		$args  = array();

		if ( 'upcoming' === $scope ) {
			$where[] = "status = 'confirmed' AND end_ts >= %d";
			$args[]  = time();
		} elseif ( 'past' === $scope ) {
			$where[] = '(status <> \'confirmed\' OR end_ts < %d)';
			$args[]  = time();
		}
		if ( $event_type_id > 0 ) {
			$where[] = 'event_type_id = %d';
			$args[]  = $event_type_id;
		}

		$order = 'upcoming' === $scope ? 'ASC' : 'DESC';
		$sql   = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . " ORDER BY start_ts {$order} LIMIT 500";
		// Il SQL è composto solo da frammenti fissi e placeholder: i valori passano da prepare().
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$sql = $args ? $wpdb->prepare( $sql, $args ) : $sql;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		return array_map( array( $this, 'hydrate' ), $rows ? $rows : array() );
	}

	/**
	 * Intervalli occupati da prenotazioni confermate in una finestra.
	 *
	 * @param int $from_ts    Inizio finestra.
	 * @param int $to_ts      Fine finestra.
	 * @param int $exclude_id Prenotazione da ignorare (per lo spostamento).
	 * @return array<int,array{0:int,1:int}>
	 */
	public function busy_intervals( int $from_ts, int $to_ts, int $exclude_id = 0 ): array {
		global $wpdb;
		$table = Schema::table( 'bookings' );
		$types = Schema::table( 'event_types' );
		// Ogni prenotazione occupa anche le pause (prima/dopo) del proprio tipo di call.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT b.start_ts - COALESCE(t.buffer_before_min, 0) * 60 AS start_ts, b.end_ts + COALESCE(t.buffer_after_min, 0) * 60 AS end_ts
				FROM {$table} b LEFT JOIN {$types} t ON t.id = b.event_type_id
				WHERE b.status = 'confirmed' AND b.id <> %d
				AND b.end_ts + COALESCE(t.buffer_after_min, 0) * 60 > %d AND b.start_ts - COALESCE(t.buffer_before_min, 0) * 60 < %d",
				$exclude_id,
				$from_ts,
				$to_ts
			),
			ARRAY_A
		);
		$out  = array();
		foreach ( $rows ? $rows : array() as $row ) {
			$out[] = array( (int) $row['start_ts'], (int) $row['end_ts'] );
		}
		return $out;
	}

	/**
	 * Decodifica e converte una riga.
	 *
	 * @param array<string,mixed> $row Riga grezza.
	 * @return array<string,mixed>
	 */
	private function hydrate( array $row ): array {
		foreach ( array( 'id', 'event_type_id', 'start_ts', 'end_ts', 'ics_sequence' ) as $key ) {
			$row[ $key ] = (int) $row[ $key ];
		}
		$row['answers'] = (array) json_decode( (string) $row['answers'], true );
		$row['status']  = BookingStatus::tryFrom( (string) $row['status'] )?->value ?? BookingStatus::Confirmed->value;
		return $row;
	}
}
