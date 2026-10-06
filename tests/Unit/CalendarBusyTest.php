<?php
/**
 * Test della conversione degli eventi Google in intervalli occupati.
 *
 * @package Mavida\BookACall
 */

declare(strict_types=1);

namespace Mavida\BookACall\Tests\Unit;

use Mavida\BookACall\Google\CalendarClient;
use PHPUnit\Framework\TestCase;

/**
 * CalendarClient contiene accessi a WordPress, quindi la classe viene caricata con una costante ABSPATH di prova.
 */
final class CalendarBusyTest extends TestCase {

	public static function setUpBeforeClass(): void {
		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', __DIR__ . '/' );
		}
		require_once dirname( __DIR__, 2 ) . '/src/Google/CalendarClient.php';
	}

	public function test_timed_event_is_busy(): void {
		$busy = CalendarClient::busy_from_events(
			array(
				array(
					'start' => array( 'dateTime' => '2026-11-18T09:00:00+01:00' ),
					'end'   => array( 'dateTime' => '2026-11-18T10:00:00+01:00' ),
				),
			)
		);
		$this->assertSame( array( array( strtotime( '2026-11-18T09:00:00+01:00' ), strtotime( '2026-11-18T10:00:00+01:00' ) ) ), $busy );
	}

	public function test_all_day_event_is_ignored(): void {
		$busy = CalendarClient::busy_from_events(
			array(
				array(
					'start' => array( 'date' => '2026-11-18' ),
					'end'   => array( 'date' => '2026-11-19' ),
				),
			)
		);
		$this->assertSame( array(), $busy );
	}

	public function test_free_cancelled_and_declined_events_are_ignored(): void {
		$time = array(
			'start' => array( 'dateTime' => '2026-11-20T09:00:00+01:00' ),
			'end'   => array( 'dateTime' => '2026-11-20T10:00:00+01:00' ),
		);
		$busy = CalendarClient::busy_from_events(
			array(
				$time + array( 'transparency' => 'transparent' ),
				$time + array( 'status' => 'cancelled' ),
				$time + array(
					'attendees' => array(
						array( 'self' => true, 'responseStatus' => 'declined' ),
					),
				),
				// Rifiutato da un altro invitato: l'evento resta occupato.
				$time + array(
					'attendees' => array(
						array( 'responseStatus' => 'declined' ),
					),
				),
			)
		);
		$this->assertCount( 1, $busy );
	}
}
