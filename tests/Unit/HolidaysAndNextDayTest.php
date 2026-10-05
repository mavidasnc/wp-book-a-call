<?php
/**
 * Test di ItalianHolidays e NextOperativeDay.
 *
 * @package Mavida\BookACall
 */

declare(strict_types=1);

namespace Mavida\BookACall\Tests\Unit;

use Mavida\BookACall\Availability\ItalianHolidays;
use Mavida\BookACall\Availability\NextOperativeDay;
use PHPUnit\Framework\TestCase;

/**
 * Verifica Pasqua, elenco festività e calcolo del prossimo giorno operativo.
 */
final class HolidaysAndNextDayTest extends TestCase {

	/**
	 * Orari lun-ven.
	 *
	 * @return array<string,array<int,array{0:string,1:string}>>
	 */
	private function weekdays(): array {
		$day = array( array( '09:00', '12:00' ) );
		return array(
			'mon' => $day,
			'tue' => $day,
			'wed' => $day,
			'thu' => $day,
			'fri' => $day,
			'sat' => array(),
			'sun' => array(),
		);
	}

	public function test_easter_dates_are_correct(): void {
		$this->assertSame( '2024-03-31', ItalianHolidays::easter( 2024 ) );
		$this->assertSame( '2025-04-20', ItalianHolidays::easter( 2025 ) );
		$this->assertSame( '2026-04-05', ItalianHolidays::easter( 2026 ) );
		$this->assertSame( '2027-03-28', ItalianHolidays::easter( 2027 ) );
		$this->assertSame( '2028-04-16', ItalianHolidays::easter( 2028 ) );
	}

	public function test_year_2026_has_all_the_national_holidays(): void {
		$days = ItalianHolidays::for_year( 2026 );
		$this->assertCount( 12, $days );
		$this->assertSame( 'Capodanno', $days['2026-01-01'] );
		$this->assertSame( 'Pasqua', $days['2026-04-05'] );
		$this->assertSame( "Lunedì dell'Angelo", $days['2026-04-06'] );
		$this->assertSame( 'Immacolata Concezione', $days['2026-12-08'] );
		$this->assertSame( 'Santo Stefano', $days['2026-12-26'] );
		$this->assertSame( array_keys( $days ), array_values( array_keys( $days ) ) );
		$sorted = array_keys( $days );
		sort( $sorted );
		$this->assertSame( $sorted, array_keys( $days ) );
	}

	public function test_monday_easter_follows_a_late_easter(): void {
		$this->assertArrayHasKey( '2027-03-29', ItalianHolidays::for_year( 2027 ) );
	}

	public function test_next_day_after_a_weekday_is_tomorrow(): void {
		// 2026-10-06 è un martedì.
		$this->assertSame( '2026-10-07', NextOperativeDay::find( '2026-10-06', $this->weekdays(), array() ) );
	}

	public function test_next_day_after_friday_skips_the_weekend(): void {
		$this->assertSame( '2026-10-12', NextOperativeDay::find( '2026-10-09', $this->weekdays(), array() ) );
	}

	public function test_holiday_monday_is_skipped(): void {
		$closed = array( array( 'date_from' => '2026-04-06', 'date_to' => '2026-04-06' ) );
		// Venerdì 3 aprile 2026: sabato, domenica (Pasqua) e lunedì (Pasquetta) sono fuori, quindi martedì 7.
		$this->assertSame( '2026-04-07', NextOperativeDay::find( '2026-04-03', $this->weekdays(), $closed ) );
	}

	public function test_exception_range_is_skipped(): void {
		$closed = array( array( 'date_from' => '2026-10-07', 'date_to' => '2026-10-08' ) );
		$this->assertSame( '2026-10-09', NextOperativeDay::find( '2026-10-06', $this->weekdays(), $closed ) );
	}

	public function test_no_hours_means_no_next_day(): void {
		$this->assertNull( NextOperativeDay::find( '2026-10-06', array(), array() ) );
	}
}
