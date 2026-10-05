<?php
/**
 * Template HTML delle email.
 *
 * @package Mavida\BookACall
 *
 * @var array<string,mixed> $vars Variabili: heading, intro, rows (etichetta => valore), links (etichetta => url), footer.
 */

defined( 'ABSPATH' ) || exit;

?>
<!DOCTYPE html>
<html>
<body style="margin:0;padding:24px;background:#f4f5f7;font-family:-apple-system,'Segoe UI',Helvetica,Arial,sans-serif;color:#1d2327;">
	<div style="max-width:560px;margin:0 auto;background:#fff;border-radius:8px;padding:32px;">
		<h1 style="font-size:22px;margin:0 0 16px;"><?php echo esc_html( $vars['heading'] ); ?></h1>
		<p style="font-size:15px;line-height:1.6;margin:0 0 20px;"><?php echo esc_html( $vars['intro'] ); ?></p>
		<table style="width:100%;border-collapse:collapse;font-size:14px;">
			<?php foreach ( $vars['rows'] as $wpbac_label => $wpbac_value ) : ?>
				<tr>
					<td style="padding:8px 12px 8px 0;color:#646970;vertical-align:top;white-space:nowrap;"><?php echo esc_html( $wpbac_label ); ?></td>
					<td style="padding:8px 0;"><?php echo nl2br( esc_html( $wpbac_value ) ); ?></td>
				</tr>
			<?php endforeach; ?>
		</table>
		<?php foreach ( $vars['links'] as $wpbac_label => $wpbac_url ) : ?>
			<p style="margin:24px 0 0;"><a href="<?php echo esc_url( $wpbac_url ); ?>" style="display:inline-block;background:#1d2327;color:#fff;text-decoration:none;padding:10px 18px;border-radius:6px;font-size:14px;"><?php echo esc_html( $wpbac_label ); ?></a></p>
		<?php endforeach; ?>
		<p style="font-size:12px;color:#8c8f94;margin:28px 0 0;"><?php echo esc_html( $vars['footer'] ); ?></p>
	</div>
</body>
</html>
