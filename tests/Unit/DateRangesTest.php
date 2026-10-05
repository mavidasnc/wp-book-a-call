<?php
/**
 * Test di DateRanges.
 *
 * @package Mavida\BookACall
 */

declare(strict_types=1);

namespace Mavida\BookACall\Tests\Unit;

use Mavida\BookACall\Availability\DateRanges;
use PHPUnit\Framework\TestCase;

/**
 * Verifica unione e divisione degli intervalli di date.
 */
final class DateRangesTest extends TestCase {

	public function test_add_creates_single_day_range(): void {
		$this->assertSame( array( array( '2026-10-06', '2026-10-06' ) ), DateRanges::add( array(), array( '2026-10-06' ) ) );
	}

	public function test_add_merges_contiguous_days(): void {
		$result = DateRanges::add( array(), array( '2026-10-08', '2026-10-06', '2026-10-07' ) );
		$this->assertSame( array( array( '2026-10-06', '2026-10-08' ) ), $result );
	}

	public function test_add_keeps_separate_ranges_apart(): void {
		$result = DateRanges::add( array( array( '2026-10-01', '2026-10-02' ) ), array( '2026-10-05' ) );
		$this->assertSame( array( array( '2026-10-01', '2026-10-02' ), array( '2026-10-05', '2026-10-05' ) ), $result );
	}

	public function test_add_merges_across_month_boundary(): void {
		$result = DateRanges::add( array( array( '2026-10-30', '2026-10-31' ) ), array( '2026-11-01' ) );
		$this->assertSame( array( array( '2026-10-30', '2026-11-01' ) ), $result );
	}

	public function test_add_inside_existing_range_changes_nothing(): void {
		$ranges = array( array( '2026-12-24', '2026-12-31' ) );
		$this->assertSame( $ranges, DateRanges::add( $ranges, array( '2026-12-26' ) ) );
	}

	public function test_remove_splits_a_range_in_the_middle(): void {
		$result = DateRanges::remove( array( array( '2026-12-24', '2026-12-31' ) ), array( '2026-12-27' ) );
		$this->assertSame( array( array( '2026-12-24', '2026-12-26' ), array( '2026-12-28', '2026-12-31' ) ), $result );
	}

	public function test_remove_edge_day_shrinks_the_range(): void {
		$result = DateRanges::remove( array( array( '2026-12-24', '2026-12-31' ) ), array( '2026-12-24', '2026-12-31' ) );
		$this->assertSame( array( array( '2026-12-25', '2026-12-30' ) ), $result );
	}

	public function test_remove_single_day_range_deletes_it(): void {
		$this->assertSame( array(), DateRanges::remove( array( array( '2026-10-06', '2026-10-06' ) ), array( '2026-10-06' ) ) );
	}

	public function test_remove_unknown_day_changes_nothing(): void {
		$ranges = array( array( '2026-10-06', '2026-10-07' ) );
		$this->assertSame( $ranges, DateRanges::remove( $ranges, array( '2026-11-01' ) ) );
	}
}
