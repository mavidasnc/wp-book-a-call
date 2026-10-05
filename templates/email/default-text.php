<?php
/**
 * Template testo semplice delle email.
 *
 * @package Mavida\BookACall
 *
 * @var array<string,mixed> $vars Variabili: heading, intro, rows (etichetta => valore), links (etichetta => url), footer.
 */

defined( 'ABSPATH' ) || exit;

// Output in text/plain: l'escape HTML qui corromperebbe il testo.
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
echo $vars['heading'] . "\n\n" . $vars['intro'] . "\n\n";
foreach ( $vars['rows'] as $wpbac_label => $wpbac_value ) {
	echo $wpbac_label . ': ' . $wpbac_value . "\n";
}
foreach ( $vars['links'] as $wpbac_label => $wpbac_url ) {
	echo "\n" . $wpbac_label . ': ' . $wpbac_url . "\n";
}
echo "\n" . $vars['footer'] . "\n";
