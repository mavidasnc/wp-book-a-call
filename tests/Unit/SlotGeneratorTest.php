<?php
/**
 * Test di SlotGenerator.
 *
 * @package Mavida\BookACall
 */

declare(strict_types=1);

namespace Mavida\BookACall\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use Mavida\BookACall\Availability\SlotGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Verifica orari settimanali, buffer, preavviso, eccezioni e ora legale.
 */
final class SlotGeneratorTest extends TestCase {

	/**
	 * Fuso dei test.
	 *
	 * @var DateTimeZone
	 */
	private DateTimeZone $tz;

	protected function setUp(): void {
		$this->tz = new DateTimeZone( 'Europe/Rome' );
	}

	/**
	 * Tipo di call di base: lunedì 09:00-12:00, slot da 30 minuti.
	 *
	 * @param array<string,mixed> $override Valori da sovrascrivere.
	 * @return array<string,mixed>
	 */
	private function event_type( array $override = array() ): array {
		return array_merge(
			array(
				'duration_min'      => 30,
				'slot_step_min'     => 30,
				'buffer_before_min' => 0,
				'buffer_after_min'  => 0,
				'min_notice_hours'  => 0,
				'max_days_ahead'    => 365,
				'weekly_hours'      => array( 'mon' => array( array( '09:00', '12:00' ) ) ),
			),
			$override
		);
	}

	/**
	 * Timestamp di una data/ora locale.
	 *
	 * @param string $local Data e ora locali.
	 * @return int
	 */
	private function ts( string $local ): int {
		return ( new DateTimeImmutable( $local, $this->tz ) )->getTimestamp();
	}

	/**
	 * Esegue generate() su un intero giorno locale.
	 *
	 * @param array<string,mixed> $et         Tipo di call.
	 * @param string              $date       Data Y-m-d.
	 * @param array               $busy       Occupati.
	 * @param array               $exceptions Eccezioni.
	 * @param string              $now        Data/ora corrente locale.
	 * @return string[] Orari locali H:i.
	 */
	private function day( array $et, string $date, array $busy = array(), array $exceptions = array(), string $now = '2026-01-01 00:00' ): array {
		$slots = ( new SlotGenerator() )->generate( $et, $this->ts( $date . ' 00:00' ), $this->ts( $date . ' 23:59' ) + 60, $busy, $exceptions, $this->ts( $now ), $this->tz );
		return array_map( fn( int $s ): string => ( new DateTimeImmutable( '@' . $s ) )->setTimezone( $this->tz )->format( 'H:i' ), $slots );
	}

	public function test_weekly_hours_produce_slots(): void {
		// 2026-10-05 è un lunedì.
		$this->assertSame( array( '09:00', '09:30', '10:00', '10:30', '11:00', '11:30' ), $this->day( $this->event_type(), '2026-10-05' ) );
	}

	public function test_day_without_hours_has_no_slots(): void {
		$this->assertSame( array(), $this->day( $this->event_type(), '2026-10-06' ) );
	}

	public function test_busy_interval_with_buffer_removes_slots(): void {
		$busy = array( array( $this->ts( '2026-10-05 10:00' ), $this->ts( '2026-10-05 10:30' ) ) );
		$et   = $this->event_type( array( 'buffer_after_min' => 30 ) );
		// Con 30 minuti di buffer dopo, lo slot 09:30 finisce alle 10:00 + 30 = 10:30 e si scontra.
		$this->assertSame( array( '09:00', '10:30', '11:00', '11:30' ), $this->day( $et, '2026-10-05', $busy ) );
	}

	public function test_min_notice_hides_near_slots(): void {
		$et = $this->event_type( array( 'min_notice_hours' => 2 ) );
		$this->assertSame( array( '10:30', '11:00', '11:30' ), $this->day( $et, '2026-10-05', array(), array(), '2026-10-05 08:30' ) );
	}

	public function test_exception_closes_the_day(): void {
		$exceptions = array( array( 'date_from' => '2026-10-01', 'date_to' => '2026-10-10' ) );
		$this->assertSame( array(), $this->day( $this->event_type(), '2026-10-05', array(), $exceptions ) );
	}

	public function test_max_days_ahead_limits_horizon(): void {
		$et = $this->event_type( array( 'max_days_ahead' => 1 ) );
		$this->assertSame( array(), $this->day( $et, '2026-10-05', array(), array(), '2026-09-01 00:00' ) );
	}

	public function test_slot_must_fit_in_window(): void {
		$et = $this->event_type( array( 'duration_min' => 60, 'slot_step_min' => 60 ) );
		$this->assertSame( array( '09:00', '10:00', '11:00' ), $this->day( $et, '2026-10-05' ) );
	}

	public function test_booked_slot_is_reported_as_taken_not_available(): void {
		$busy  = array( array( $this->ts( '2026-10-05 10:00' ), $this->ts( '2026-10-05 10:30' ) ) );
		$taken = array();
		$slots = ( new SlotGenerator() )->generate( $this->event_type(), $this->ts( '2026-10-05 00:00' ), $this->ts( '2026-10-06 00:00' ), $busy, array(), $this->ts( '2026-01-01 00:00' ), $this->tz, array(), 0, $taken );
		$this->assertNotContains( $this->ts( '2026-10-05 10:00' ), $slots );
		$this->assertSame( array( $this->ts( '2026-10-05 10:00' ) ), $taken );
		$this->assertCount( 5, $slots );
	}

	public function test_full_day_reports_every_slot_as_taken(): void {
		$taken = array();
		$slots = ( new SlotGenerator() )->generate( $this->event_type(), $this->ts( '2026-10-05 00:00' ), $this->ts( '2026-10-06 00:00' ), array(), array(), $this->ts( '2026-01-01 00:00' ), $this->tz, array( '2026-10-05' => 2 ), 2, $taken );
		$this->assertSame( array(), $slots );
		$this->assertCount( 6, $taken );
	}

	public function test_closed_day_has_neither_available_nor_taken(): void {
		$taken      = array();
		$exceptions = array( array( 'date_from' => '2026-10-05', 'date_to' => '2026-10-05' ) );
		$busy       = array( array( $this->ts( '2026-10-05 10:00' ), $this->ts( '2026-10-05 10:30' ) ) );
		$slots      = ( new SlotGenerator() )->generate( $this->event_type(), $this->ts( '2026-10-05 00:00' ), $this->ts( '2026-10-06 00:00' ), $busy, $exceptions, $this->ts( '2026-01-01 00:00' ), $this->tz, array(), 0, $taken );
		$this->assertSame( array(), $slots );
		$this->assertSame( array(), $taken );
	}

	public function test_slots_too_soon_are_not_reported_as_taken(): void {
		$taken = array();
		$et    = $this->event_type( array( 'min_notice_hours' => 2 ) );
		( new SlotGenerator() )->generate( $et, $this->ts( '2026-10-05 00:00' ), $this->ts( '2026-10-06 00:00' ), array(), array(), $this->ts( '2026-10-05 08:30' ), $this->tz, array(), 0, $taken );
		$this->assertSame( array(), $taken );
	}

	public function test_full_day_has_no_slots(): void {
		$slots = ( new SlotGenerator() )->generate( $this->event_type(), $this->ts( '2026-10-05 00:00' ), $this->ts( '2026-10-06 00:00' ), array(), array(), $this->ts( '2026-01-01 00:00' ), $this->tz, array( '2026-10-05' => 2 ), 2 );
		$this->assertSame( array(), $slots );
	}

	public function test_day_below_limit_keeps_slots(): void {
		$slots = ( new SlotGenerator() )->generate( $this->event_type(), $this->ts( '2026-10-05 00:00' ), $this->ts( '2026-10-06 00:00' ), array(), array(), $this->ts( '2026-01-01 00:00' ), $this->tz, array( '2026-10-05' => 1 ), 2 );
		$this->assertCount( 6, $slots );
	}

	public function test_zero_limit_means_unlimited(): void {
		$slots = ( new SlotGenerator() )->generate( $this->event_type(), $this->ts( '2026-10-05 00:00' ), $this->ts( '2026-10-06 00:00' ), array(), array(), $this->ts( '2026-01-01 00:00' ), $this->tz, array( '2026-10-05' => 9 ), 0 );
		$this->assertCount( 6, $slots );
	}

	public function test_daylight_saving_change_keeps_local_hours(): void {
		// Il 2026-10-25 (domenica) l'ora legale finisce: gli orari locali restano 09:00-10:00.
		$et = $this->event_type( array( 'weekly_hours' => array( 'sun' => array( array( '09:00', '10:00' ) ) ) ) );
		$this->assertSame( array( '09:00', '09:30' ), $this->day( $et, '2026-10-25' ) );
	}
}
