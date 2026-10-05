<?php
/**
 * Attivazione del plugin.
 *
 * @package Mavida\BookACall
 */

declare(strict_types=1);

namespace Mavida\BookACall;

use Mavida\BookACall\Database\EventTypeRepository;
use Mavida\BookACall\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Controlli e setup eseguiti all'attivazione.
 */
final class Activator {

	/**
	 * Versione minima di PHP.
	 *
	 * @var string
	 */
	private const MIN_PHP = '8.3';

	/**
	 * Versione minima di WordPress.
	 *
	 * @var string
	 */
	private const MIN_WP = '6.5';

	/**
	 * Verifica i requisiti minimi.
	 *
	 * @return void
	 */
	public static function activate(): void {
		global $wp_version;

		if ( version_compare( PHP_VERSION, self::MIN_PHP, '<' ) || version_compare( $wp_version, self::MIN_WP, '<' ) ) {
			wp_die(
				esc_html(
					sprintf(
						/* translators: 1: PHP minimo, 2: WordPress minimo. */
						__( 'WP Book a Call richiede PHP %1$s e WordPress %2$s o superiori.', 'wp-book-a-call' ),
						self::MIN_PHP,
						self::MIN_WP
					)
				)
			);
		}

		// Tabelle e tipo di call di esempio al primo avvio.
		Schema::install();
		self::seed_default_event_type();
	}

	/**
	 * Crea una "Call conoscitiva" da 30 minuti (lun-ven) se non esiste alcun tipo di call.
	 *
	 * @return void
	 */
	private static function seed_default_event_type(): void {
		$types = new EventTypeRepository();
		if ( $types->all() ) {
			return;
		}

		$day = array( array( '10:00', '12:30' ), array( '15:00', '18:00' ) );
		$types->save(
			array(
				'title'            => 'Call conoscitiva',
				'slug'             => 'call-conoscitiva',
				'description'      => 'Una chiacchierata di 30 minuti per conoscerci e capire come posso aiutarti.',
				'duration_min'     => 30,
				'slot_step_min'    => 30,
				'buffer_after_min' => 10,
				'min_notice_hours' => 12,
				'max_days_ahead'   => 60,
				'location_type'    => 'meet',
				'weekly_hours'     => array(
					'mon' => $day,
					'tue' => $day,
					'wed' => $day,
					'thu' => $day,
					'fri' => $day,
				),
				'questions'        => array(
					array(
						'label'    => 'Di cosa vorresti parlare?',
						'type'     => 'textarea',
						'required' => false,
					),
				),
				'active'           => true,
			)
		);
	}
}
