<?php
/**
 * Schema del database.
 *
 * @package Mavida\BookACall
 */

declare(strict_types=1);

namespace Mavida\BookACall\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Crea e aggiorna le tabelle del plugin.
 */
final class Schema {

	/**
	 * Versione dello schema (incrementare a ogni modifica delle tabelle).
	 *
	 * @var string
	 */
	public const DB_VERSION = '1';

	/**
	 * Nome completo di una tabella del plugin.
	 *
	 * @param string $name Nome breve: event_types, bookings, exceptions.
	 * @return string
	 */
	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'wpbac_' . $name;
	}

	/**
	 * Aggancia l'aggiornamento automatico dello schema.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'plugins_loaded', array( self::class, 'maybe_upgrade' ), 20 );
	}

	/**
	 * Esegue install() se la versione salvata è diversa.
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( 'wpbac_db_version' ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Crea o aggiorna le tabelle con dbDelta.
	 *
	 * @return void
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$types   = self::table( 'event_types' );
		$book    = self::table( 'bookings' );
		$exc     = self::table( 'exceptions' );

		// Tipi di call.
		dbDelta(
			"CREATE TABLE {$types} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			slug varchar(100) NOT NULL,
			title varchar(200) NOT NULL,
			description text NULL,
			duration_min smallint(5) unsigned NOT NULL DEFAULT 30,
			slot_step_min smallint(5) unsigned NOT NULL DEFAULT 30,
			buffer_before_min smallint(5) unsigned NOT NULL DEFAULT 0,
			buffer_after_min smallint(5) unsigned NOT NULL DEFAULT 0,
			min_notice_hours smallint(5) unsigned NOT NULL DEFAULT 12,
			max_days_ahead smallint(5) unsigned NOT NULL DEFAULT 60,
			location_type varchar(20) NOT NULL DEFAULT 'meet',
			location_value varchar(255) NOT NULL DEFAULT '',
			weekly_hours longtext NULL,
			questions longtext NULL,
			active tinyint(1) NOT NULL DEFAULT 1,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug)
		) {$charset};"
		);

		// Prenotazioni (orari in timestamp UNIX UTC).
		dbDelta(
			"CREATE TABLE {$book} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_type_id bigint(20) unsigned NOT NULL,
			start_ts bigint(20) unsigned NOT NULL,
			end_ts bigint(20) unsigned NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'confirmed',
			name varchar(200) NOT NULL,
			email varchar(200) NOT NULL,
			timezone varchar(64) NOT NULL DEFAULT 'UTC',
			answers longtext NULL,
			token_hash varchar(64) NOT NULL,
			source_url varchar(500) NOT NULL DEFAULT '',
			google_event_id varchar(255) NOT NULL DEFAULT '',
			meet_url varchar(255) NOT NULL DEFAULT '',
			ics_sequence smallint(5) unsigned NOT NULL DEFAULT 0,
			ip_hash varchar(64) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			cancelled_at datetime NULL,
			PRIMARY KEY  (id),
			KEY start_end (start_ts,end_ts),
			KEY event_type_id (event_type_id)
		) {$charset};"
		);

		// Eccezioni (ferie, chiusure).
		dbDelta(
			"CREATE TABLE {$exc} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_type_id bigint(20) unsigned NULL,
			date_from date NOT NULL,
			date_to date NOT NULL,
			note varchar(255) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY date_range (date_from,date_to)
		) {$charset};"
		);

		update_option( 'wpbac_db_version', self::DB_VERSION );
	}
}
