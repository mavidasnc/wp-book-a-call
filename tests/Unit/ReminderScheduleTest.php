<?php
/**
 * Test di ReminderSchedule.
 *
 * @package Mavida\BookACall
 */

declare(strict_types=1);

namespace Mavida\BookACall\Tests\Unit;

use Mavida\BookACall\Reminders\ReminderSchedule;
use PHPUnit\Framework\TestCase;

/**
 * Verifica quali promemoria si possono programmare su Resend.
 */
final class ReminderScheduleTest extends TestCase {

	private const NOW = 1_800_000_000;

	public function test_both_reminders_are_planned_for_a_call_in_a_few_days(): void {
		$start = self::NOW + 3 * 86400;
		$plan  = ReminderSchedule::plan( $start, self::NOW, true, true );
		$this->assertSame(
			array(
				'24' => $start - 86400,
				'1'  => $start - 3600,
			),
			$plan
		);
	}

	public function test_disabled_reminders_are_skipped(): void {
		$start = self::NOW + 3 * 86400;
		$this->assertSame( array( '1' => $start - 3600 ), ReminderSchedule::plan( $start, self::NOW, false, true ) );
		$this->assertSame( array(), ReminderSchedule::plan( $start, self::NOW, false, false ) );
	}

	public function test_past_or_too_close_reminders_are_skipped(): void {
		// Call tra 10 ore: il promemoria a 24 ore è già passato, quello a 1 ora è programmabile.
		$start = self::NOW + 10 * 3600;
		$this->assertSame( array( '1' => $start - 3600 ), ReminderSchedule::plan( $start, self::NOW, true, true ) );

		// Call tra 1 ora e 1 minuto: l'invio cadrebbe tra 60 secondi, sotto il margine minimo.
		$this->assertSame( array(), ReminderSchedule::plan( self::NOW + 3660, self::NOW, true, true ) );
	}

	public function test_reminders_beyond_the_resend_horizon_are_left_to_cron(): void {
		// Call tra 40 giorni: nessun invio programmabile ora.
		$this->assertSame( array(), ReminderSchedule::plan( self::NOW + 40 * 86400, self::NOW, true, true ) );

		// Call tra 29 giorni e 12 ore: il promemoria a 24 ore (28 giorni e 12 ore) rientra, quello a 1 ora no.
		$start = self::NOW + 29 * 86400 + 12 * 3600;
		$this->assertSame( array( '24' => $start - 86400 ), ReminderSchedule::plan( $start, self::NOW, true, true ) );
	}
}
