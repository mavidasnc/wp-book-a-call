<?php
/**
 * Test di ClosedDayMap.
 *
 * @package Mavida\BookACall
 */

declare(strict_types=1);

namespace Mavida\BookACall\Tests\Unit;

use Mavida\BookACall\Availability\ClosedDayMap;
use PHPUnit\Framework\TestCase;

/**
 * Verifica motivo, precedenza e limiti della mappa dei giorni chiusi.
 */
final class ClosedDayMapTest extends TestCase {

	public function test_exception_range_is_marked_closed(): void {
		$map = ClosedDayMap::build(
			'2026-10-05',
			'2026-10-12',
			array( array( 'date_from' => '2026-10-07', 'date_to' => '2026-10-08' ) ),
			array()
		);
		$this->assertSame( array( '2026-10-07', '2026-10-08' ), array_keys( $map ) );
		$this->assertSame( 'closed', $map['2026-10-07']['reason'] );
	}

	public function test_holiday_wins_over_exception_and_keeps_its_name(): void {
		$map = ClosedDayMap::build(
			'2026-12-24',
			'2026-12-27',
			array( array( 'date_from' => '2026-12-25', 'date_to' => '2026-12-26' ) ),
			array( '2026-12-25' => 'Natale' )
		);
		$this->assertSame( 'holiday', $map['2026-12-25']['reason'] );
		$this->assertSame( 'Natale', $map['2026-12-25']['label'] );
		$this->assertSame( 'closed', $map['2026-12-26']['reason'] );
	}

	public function test_days_outside_the_window_are_left_out(): void {
		$map = ClosedDayMap::build(
			'2026-10-10',
			'2026-10-11',
			array( array( 'date_from' => '2026-10-01', 'date_to' => '2026-10-31' ) ),
			array( '2026-12-25' => 'Natale' )
		);
		$this->assertSame( array( '2026-10-10', '2026-10-11' ), array_keys( $map ) );
	}
}
