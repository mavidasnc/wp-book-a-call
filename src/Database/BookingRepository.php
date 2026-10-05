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
	 * @param int    $limit         Numero massimo di righe.
	 * @return array<int,array<string,mixed>>
	 */
	public function list( string $scope = 'upcoming', int $event_type_id = 0, int $limit = 500 ): array {
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
		$sql   = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . " ORDER BY start_ts {$order} LIMIT " . max( 1, $limit );
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
	 * Inizi delle prenotazioni confermate in una finestra (per il limite giornaliero).
	 *
	 * @param int $from_ts    Inizio finestra.
	 * @param int $to_ts      Fine finestra.
	 * @param int $exclude_id Prenotazione da ignorare (spostamento).
	 * @return int[]
	 */
	public function starts_between( int $from_ts, int $to_ts, int $exclude_id = 0 ): array {
		global $wpdb;
		$table = Schema::table( 'bookings' );
		$rows  = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT start_ts FROM {$table} WHERE status = 'confirmed' AND id <> %d AND start_ts >= %d AND start_ts < %d",
				$exclude_id,
				$from_ts,
				$to_ts
			)
		);
		return array_map( 'intval', $rows ? $rows : array() );
	}

	/**
	 * Esiste una prenotazione confermata e non ancora conclusa per questa email?
	 *
	 * @param string $email Email del cliente (maiuscole ignorate).
	 * @param int    $now   Timestamp corrente.
	 * @return bool
	 */
	public function has_active_for_email( string $email, int $now ): bool {
		global $wpdb;
		$table = Schema::table( 'bookings' );
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE status = 'confirmed' AND end_ts > %d AND LOWER(email) = %s",
				$now,
				strtolower( $email )
			)
		);
		return (int) $count > 0;
	}

	/**
	 * Prenotazioni di una persona (per exporter ed eraser privacy), a pagine.
	 *
	 * @param string $email    Email (maiuscole ignorate).
	 * @param int    $page     Pagina, da 1.
	 * @param int    $per_page Righe per pagina.
	 * @return array<int,array<string,mixed>>
	 */
	public function by_email( string $email, int $page, int $per_page ): array {
		global $wpdb;
		$table = Schema::table( 'bookings' );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE LOWER(email) = %s ORDER BY id ASC LIMIT %d OFFSET %d",
				strtolower( $email ),
				$per_page,
				max( 0, $page - 1 ) * $per_page
			),
			ARRAY_A
		);
		return array_map( array( $this, 'hydrate' ), $rows ? $rows : array() );
	}

	/**
	 * Rende anonima una prenotazione: restano solo data, ora e tipo di call.
	 *
	 * @param int $id Id della prenotazione.
	 * @return void
	 */
	public function anonymize( int $id ): void {
		$this->update(
			$id,
			array(
				'name'            => 'Utente rimosso',
				'email'           => 'rimosso-' . $id . '@invalid.invalid',
				'answers'         => '[]',
				'token_hash'      => '',
				'source_url'      => '',
				'google_event_id' => '',
				'meet_url'        => '',
				'ip_hash'         => '',
			)
		);
	}

	/**
	 * Prenotazioni confermate per cui è ora di inviare un promemoria.
	 *
	 * @param string $kind '24' (24 ore prima) o '1' (1 ora prima).
	 * @param int    $now  Timestamp corrente.
	 * @return array<int,array<string,mixed>>
	 */
	public function due_reminders( string $kind, int $now ): array {
		global $wpdb;
		$table  = Schema::table( 'bookings' );
		$column = '1' === $kind ? 'reminder_1_sent' : 'reminder_24_sent';
		$window = '1' === $kind ? HOUR_IN_SECONDS : DAY_IN_SECONDS;
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = 'confirmed' AND {$column} = 0 AND start_ts > %d AND start_ts <= %d",
				$now,
				$now + $window
			),
			ARRAY_A
		);
		return array_map( array( $this, 'hydrate' ), $rows ? $rows : array() );
	}

	/**
	 * Segna un promemoria come inviato.
	 *
	 * @param int    $id   Id della prenotazione.
	 * @param string $kind '24' o '1'.
	 * @return void
	 */
	public function mark_reminder( int $id, string $kind ): void {
		$this->set_reminder( $id, $kind, 1, '' );
	}

	/**
	 * Imposta stato e riferimento (id dell'invio programmato su Resend) di un promemoria.
	 * Stato: 0 da fare, 1 inviato o non necessario, 2 programmato su Resend.
	 *
	 * @param int    $id    Id della prenotazione.
	 * @param string $kind  '24' o '1'.
	 * @param int    $state Stato del promemoria.
	 * @param string $ref   Id dell'invio su Resend ('' se nessuno).
	 * @return void
	 */
	public function set_reminder( int $id, string $kind, int $state, string $ref ): void {
		$prefix = '1' === $kind ? 'reminder_1' : 'reminder_24';
		$this->update(
			$id,
			array(
				$prefix . '_sent' => $state,
				$prefix . '_ref'  => $ref,
			)
		);
	}

	/**
	 * Prenotazioni confermate con un promemoria da programmare su Resend: ancora da fare (stato 0)
	 * e con l'invio compreso nell'orizzonte indicato.
	 *
	 * @param string $kind    '24' o '1'.
	 * @param int    $now     Timestamp corrente.
	 * @param int    $horizon Secondi di anticipo massimi per la programmazione.
	 * @return array<int,array<string,mixed>>
	 */
	public function unscheduled_reminders( string $kind, int $now, int $horizon ): array {
		global $wpdb;
		$table  = Schema::table( 'bookings' );
		$column = '1' === $kind ? 'reminder_1_sent' : 'reminder_24_sent';
		$offset = '1' === $kind ? HOUR_IN_SECONDS : DAY_IN_SECONDS;
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = 'confirmed' AND {$column} = 0 AND start_ts > %d AND start_ts <= %d",
				$now + $offset,
				$now + $offset + $horizon
			),
			ARRAY_A
		);
		return array_map( array( $this, 'hydrate' ), $rows ? $rows : array() );
	}

	/**
	 * Prenotazioni con almeno un promemoria programmato su Resend (stato 2), ancora da venire.
	 *
	 * @param int $now Timestamp corrente.
	 * @return array<int,array<string,mixed>>
	 */
	public function with_scheduled_reminders( int $now ): array {
		global $wpdb;
		$table = Schema::table( 'bookings' );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE start_ts > %d AND ( reminder_24_sent = 2 OR reminder_1_sent = 2 )",
				$now
			),
			ARRAY_A
		);
		return array_map( array( $this, 'hydrate' ), $rows ? $rows : array() );
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
