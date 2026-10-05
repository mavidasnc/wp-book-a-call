<?php
/**
 * Repository dei tipi di call.
 *
 * @package Mavida\BookACall
 */

declare(strict_types=1);

namespace Mavida\BookACall\Database;

// Accesso diretto alle tabelle custom del plugin: nessuna cache applicabile.
// phpcs:disable WordPress.DB.DirectDatabaseQuery
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

defined( 'ABSPATH' ) || exit;

/**
 * CRUD dei tipi di call. Le righe sono array con JSON già decodificato.
 */
final class EventTypeRepository {

	/**
	 * Giorni della settimana usati come chiavi di weekly_hours.
	 *
	 * @var string[]
	 */
	public const DAYS = array( 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' );

	/**
	 * Tutti i tipi di call.
	 *
	 * @param bool $only_active Solo quelli attivi.
	 * @return array<int,array<string,mixed>>
	 */
	public function all( bool $only_active = false ): array {
		global $wpdb;
		$table = Schema::table( 'event_types' );
		$where = $only_active ? 'WHERE active = 1' : '';
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} {$where} ORDER BY id ASC", ARRAY_A );
		return array_map( array( $this, 'hydrate' ), $rows ? $rows : array() );
	}

	/**
	 * Tipo di call per id.
	 *
	 * @param int $id Id.
	 * @return array<string,mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;
		$table = Schema::table( 'event_types' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Tipo di call per slug.
	 *
	 * @param string $slug Slug.
	 * @return array<string,mixed>|null
	 */
	public function find_by_slug( string $slug ): ?array {
		global $wpdb;
		$table = Schema::table( 'event_types' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE slug = %s", $slug ), ARRAY_A );
		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Crea (id = 0) o aggiorna un tipo di call, normalizzando l'input.
	 *
	 * @param array<string,mixed> $data Dati grezzi.
	 * @param int                 $id   Id da aggiornare, 0 per creare.
	 * @return int|\WP_Error Id salvato.
	 */
	public function save( array $data, int $id = 0 ): int|\WP_Error {
		global $wpdb;

		$title = sanitize_text_field( (string) ( $data['title'] ?? '' ) );
		if ( '' === $title ) {
			return new \WP_Error( 'wpbac_invalid', __( 'Il titolo è obbligatorio.', 'wp-book-a-call' ), array( 'status' => 400 ) );
		}

		$slug = sanitize_title( (string) ( $data['slug'] ?? '' ) );
		$slug = '' !== $slug ? $slug : sanitize_title( $title );

		// Lo slug deve essere unico.
		$existing = $this->find_by_slug( $slug );
		if ( $existing && (int) $existing['id'] !== $id ) {
			return new \WP_Error( 'wpbac_slug_taken', __( 'Esiste già un tipo di call con questo slug.', 'wp-book-a-call' ), array( 'status' => 400 ) );
		}

		$row = array(
			'slug'              => $slug,
			'title'             => $title,
			'description'       => wp_kses_post( (string) ( $data['description'] ?? '' ) ),
			'duration_min'      => max( 5, min( 480, (int) ( $data['duration_min'] ?? 30 ) ) ),
			'slot_step_min'     => max( 5, min( 480, (int) ( $data['slot_step_min'] ?? 30 ) ) ),
			'buffer_before_min' => max( 0, min( 240, (int) ( $data['buffer_before_min'] ?? 0 ) ) ),
			'buffer_after_min'  => max( 0, min( 240, (int) ( $data['buffer_after_min'] ?? 0 ) ) ),
			'min_notice_hours'  => max( 0, min( 720, (int) ( $data['min_notice_hours'] ?? 12 ) ) ),
			'max_days_ahead'    => max( 1, min( 365, (int) ( $data['max_days_ahead'] ?? 60 ) ) ),
			'location_type'     => in_array( $data['location_type'] ?? '', array( 'meet', 'phone', 'custom' ), true ) ? $data['location_type'] : 'meet',
			'location_value'    => sanitize_text_field( (string) ( $data['location_value'] ?? '' ) ),
			'weekly_hours'      => wp_json_encode( $this->normalize_hours( $data['weekly_hours'] ?? array() ) ),
			'questions'         => wp_json_encode( $this->normalize_questions( $data['questions'] ?? array() ) ),
			'active'            => ! empty( $data['active'] ) ? 1 : 0,
			'updated_at'        => current_time( 'mysql', true ),
		);

		$table = Schema::table( 'event_types' );
		if ( $id > 0 ) {
			$wpdb->update( $table, $row, array( 'id' => $id ) );
			return $id;
		}

		$row['created_at'] = $row['updated_at'];
		$wpdb->insert( $table, $row );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Elimina un tipo di call.
	 *
	 * @param int $id Id.
	 * @return void
	 */
	public function delete( int $id ): void {
		global $wpdb;
		$wpdb->delete( Schema::table( 'event_types' ), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Proiezione pubblica di un tipo di call (solo ciò che serve al frontend).
	 *
	 * @param array<string,mixed> $event_type Tipo di call.
	 * @return array<string,mixed>
	 */
	public static function to_public( array $event_type ): array {
		return array(
			'slug'          => $event_type['slug'],
			'title'         => $event_type['title'],
			'description'   => $event_type['description'],
			'duration_min'  => $event_type['duration_min'],
			'location_type' => $event_type['location_type'],
			'questions'     => $event_type['questions'],
		);
	}

	/**
	 * Decodifica i campi JSON e converte i tipi numerici.
	 *
	 * @param array<string,mixed> $row Riga grezza.
	 * @return array<string,mixed>
	 */
	private function hydrate( array $row ): array {
		foreach ( array( 'id', 'duration_min', 'slot_step_min', 'buffer_before_min', 'buffer_after_min', 'min_notice_hours', 'max_days_ahead' ) as $key ) {
			$row[ $key ] = (int) $row[ $key ];
		}
		$row['active']       = (bool) $row['active'];
		$row['weekly_hours'] = (array) json_decode( (string) $row['weekly_hours'], true );
		$row['questions']    = (array) json_decode( (string) $row['questions'], true );
		return $row;
	}

	/**
	 * Mantiene solo giorni noti e fasce HH:MM valide con inizio < fine.
	 *
	 * @param mixed $hours Orari grezzi.
	 * @return array<string,array<int,array{0:string,1:string}>>
	 */
	private function normalize_hours( mixed $hours ): array {
		$clean = array();
		if ( ! is_array( $hours ) ) {
			return $clean;
		}
		foreach ( self::DAYS as $day ) {
			$clean[ $day ] = array();
			foreach ( (array) ( $hours[ $day ] ?? array() ) as $range ) {
				if ( ! is_array( $range ) || 2 !== count( $range ) ) {
					continue;
				}
				$range = array_values( $range );
				$ok    = preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', (string) $range[0] ) && preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', (string) $range[1] );
				if ( $ok && $range[0] < $range[1] ) {
					$clean[ $day ][] = array( (string) $range[0], (string) $range[1] );
				}
			}
		}
		return $clean;
	}

	/**
	 * Normalizza le domande personalizzate.
	 *
	 * @param mixed $questions Domande grezze.
	 * @return array<int,array<string,mixed>>
	 */
	private function normalize_questions( mixed $questions ): array {
		$clean = array();
		if ( ! is_array( $questions ) ) {
			return $clean;
		}
		foreach ( $questions as $index => $question ) {
			$label = sanitize_text_field( (string) ( $question['label'] ?? '' ) );
			if ( '' === $label ) {
				continue;
			}
			$clean[] = array(
				'id'       => 'q' . ( $index + 1 ),
				'label'    => $label,
				'type'     => 'textarea' === ( $question['type'] ?? '' ) ? 'textarea' : 'text',
				'required' => ! empty( $question['required'] ),
			);
		}
		return $clean;
	}
}
