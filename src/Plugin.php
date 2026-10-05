<?php
/**
 * Bootstrap del plugin.
 *
 * @package Mavida\BookACall
 */

declare(strict_types=1);

namespace Mavida\BookACall;

use Mavida\BookACall\Admin\AdminMenu;
use Mavida\BookACall\Availability\AvailabilityService;
use Mavida\BookACall\Availability\SlotGenerator;
use Mavida\BookACall\Blocks\BlockRegistrar;
use Mavida\BookACall\Booking\BookingService;
use Mavida\BookACall\Calendar\IcsBuilder;
use Mavida\BookACall\Database\BookingRepository;
use Mavida\BookACall\Database\EventTypeRepository;
use Mavida\BookACall\Database\ExceptionRepository;
use Mavida\BookACall\Database\Schema;
use Mavida\BookACall\Email\EmailSender;
use Mavida\BookACall\Google\CalendarClient;
use Mavida\BookACall\Google\OAuthClient;
use Mavida\BookACall\REST\AdminController;
use Mavida\BookACall\REST\PublicController;
use Mavida\BookACall\REST\RestController;
use Mavida\BookACall\REST\UpdateController;
use Mavida\BookACall\Reminders\ReminderService;
use Mavida\BookACall\Support\Updater;
use Mavida\BookACall\Webhook\WebhookSender;

defined( 'ABSPATH' ) || exit;

/**
 * Singleton che collega tutti i componenti del plugin.
 */
final class Plugin {

	/**
	 * Istanza unica.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Evita doppie inizializzazioni.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Costruttore privato (singleton).
	 */
	private function __construct() {}

	/**
	 * Restituisce l'istanza unica.
	 *
	 * @return Plugin
	 */
	public static function get_instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Registra i componenti.
	 *
	 * @return void
	 */
	public function init(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		// Aggiornamenti automatici dalle release GitHub.
		Updater::init();

		// Traduzioni: da WP 6.7 vanno caricate non prima di init.
		add_action( 'init', array( $this, 'load_textdomain' ) );

		// Aggiorna lo schema del database se la versione è cambiata.
		Schema::register();

		// Dipendenze condivise.
		$types      = new EventTypeRepository();
		$bookings   = new BookingRepository();
		$exceptions = new ExceptionRepository();
		$oauth      = new OAuthClient();
		$calendar   = new CalendarClient( $oauth );
		$emails     = new EmailSender( new IcsBuilder() );
		$avail      = new AvailabilityService( $bookings, $exceptions, $calendar, new SlotGenerator() );
		$service    = new BookingService( $bookings, $avail, $calendar, $emails );

		// Le risposte REST del plugin non devono mai finire nelle cache di pagina.
		add_filter( 'rest_post_dispatch', array( RestController::class, 'no_cache' ), 10, 3 );

		// LiteSpeed Cache unisce gli script in un file con URL fisso (cache di un anno): dopo un
		// aggiornamento i browser userebbero il JS vecchio. Gli script del plugin restano separati.
		foreach ( array( 'litespeed_optimize_js_excludes', 'litespeed_optm_js_defer_exc' ) as $wpbac_filter ) {
			add_filter( $wpbac_filter, array( self::class, 'exclude_from_litespeed' ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Hook di LiteSpeed Cache.
		}

		// Componenti.
		( new BlockRegistrar() )->register();
		( new PublicController( $types, $bookings, $avail, $service ) )->register();
		$webhook = new WebhookSender();
		$webhook->register();
		( new ReminderService( $bookings, $types, $emails, $service ) )->register();
		( new AdminController( $types, $bookings, $exceptions, $service, $emails, $oauth, $webhook ) )->register();
		( new UpdateController() )->register();

		if ( is_admin() ) {
			( new AdminMenu() )->register();
			$oauth->register();
		}
	}

	/**
	 * Aggiunge gli script del plugin alle esclusioni dell'ottimizzazione JS di LiteSpeed Cache.
	 *
	 * @param mixed $excludes Elenco attuale delle esclusioni.
	 * @return array<int,string>
	 */
	public static function exclude_from_litespeed( mixed $excludes ): array {
		$excludes   = is_array( $excludes ) ? $excludes : array_filter( array_map( 'trim', explode( "\n", (string) $excludes ) ) );
		$excludes[] = 'wp-book-a-call/build/';
		return array_values( array_unique( $excludes ) );
	}

	/**
	 * Carica il text domain.
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'wp-book-a-call', false, dirname( plugin_basename( WPBAC_PLUGIN_FILE ) ) . '/languages' );
	}
}
