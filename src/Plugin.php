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

		// Componenti.
		( new BlockRegistrar() )->register();
		( new PublicController( $types, $bookings, $avail, $service ) )->register();
		( new AdminController( $types, $bookings, $exceptions, $service, $emails, $oauth ) )->register();

		if ( is_admin() ) {
			( new AdminMenu() )->register();
			$oauth->register();
		}
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
