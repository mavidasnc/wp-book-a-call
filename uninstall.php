<?php
/**
 * Pulizia alla disinstallazione.
 *
 * @package Mavida\BookACall
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// I dati si cancellano solo se l'admin lo ha chiesto nelle impostazioni.
$wpbac_settings = get_option( 'wpbac_settings', array() );
if ( empty( $wpbac_settings['delete_data_on_uninstall'] ) ) {
	return;
}

global $wpdb;

// Tabelle del plugin.
// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders
foreach ( array( 'event_types', 'bookings', 'exceptions' ) as $wpbac_table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wpbac_{$wpbac_table}" );
}

// Option e transient.
delete_option( 'wpbac_settings' );
delete_option( 'wpbac_db_version' );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_wpbac\_%' OR option_name LIKE '\_transient\_timeout\_wpbac\_%'" );
