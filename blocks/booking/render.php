<?php
/**
 * Render del blocco wpbac/booking.
 *
 * @package Mavida\BookACall
 *
 * @var array $attributes Attributi del blocco.
 */

use Mavida\BookACall\Database\EventTypeRepository;
use Mavida\BookACall\Support\Settings;

defined( 'ABSPATH' ) || exit;

// Tipo di call scelto nel blocco; se manca si usa il primo attivo.
$wpbac_types = new EventTypeRepository();
$wpbac_event = '' !== ( $attributes['eventTypeSlug'] ?? '' ) ? $wpbac_types->find_by_slug( $attributes['eventTypeSlug'] ) : null;
if ( ! $wpbac_event ) {
	$wpbac_all   = $wpbac_types->all( true );
	$wpbac_event = $wpbac_all ? $wpbac_all[0] : null;
}

if ( ! $wpbac_event || ! $wpbac_event['active'] ) {
	// Visitatori: nulla. Editor: un avviso.
	if ( current_user_can( 'edit_posts' ) ) {
		echo '<p>' . esc_html__( 'Book a Call: nessun tipo di call attivo. Creane uno da Book a Call > Tipi di call.', 'wp-book-a-call' ) . '</p>';
	}
	return;
}

// Configurazione letta dallo script del frontend.
$wpbac_config = array(
	'apiRoot'              => esc_url_raw( rest_url( 'wpbac/v1' ) ),
	'event'                => EventTypeRepository::to_public( $wpbac_event ),
	'hostName'             => Settings::host_name(),
	'privacyUrl'           => (string) Settings::get( 'privacy_url' ),
	'siteTimezone'         => wp_timezone_string(),
	'showHostInfo'         => (bool) ( $attributes['showHostInfo'] ?? true ),
	'showTimezoneSelector' => (bool) ( $attributes['showTimezoneSelector'] ?? true ),
);

?>
<div <?php echo get_block_wrapper_attributes(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<div class="wpbac-booking__root" data-config="<?php echo esc_attr( wp_json_encode( $wpbac_config ) ); ?>">
		<noscript><?php esc_html_e( 'Per prenotare una call è necessario abilitare JavaScript.', 'wp-book-a-call' ); ?></noscript>
	</div>
</div>
