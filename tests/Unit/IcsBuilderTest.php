<?php
/**
 * Test di IcsBuilder.
 *
 * @package Mavida\BookACall
 */

declare(strict_types=1);

namespace Mavida\BookACall\Tests\Unit;

use Mavida\BookACall\Calendar\IcsBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Verifica struttura, escape e folding del file iCalendar.
 */
final class IcsBuilderTest extends TestCase {

	/**
	 * Dati di base di un evento.
	 *
	 * @param array<string,mixed> $override Valori da sovrascrivere.
	 * @return array<string,mixed>
	 */
	private function data( array $override = array() ): array {
		return array_merge(
			array(
				'uid'             => 'booking-1@example.com',
				'start_ts'        => 1790000000,
				'end_ts'          => 1790001800,
				'summary'         => 'Call conoscitiva',
				'organizer_name'  => 'Maurizio',
				'organizer_email' => 'maurizio@mavida.com',
				'attendee_name'   => 'Mario Rossi',
				'attendee_email'  => 'mario@example.com',
			),
			$override
		);
	}

	public function test_request_has_required_fields(): void {
		$ics = ( new IcsBuilder() )->build( $this->data() );
		$this->assertStringContainsString( "BEGIN:VCALENDAR\r\n", $ics );
		$this->assertStringContainsString( "METHOD:REQUEST\r\n", $ics );
		$this->assertStringContainsString( "UID:booking-1@example.com\r\n", $ics );
		$this->assertStringContainsString( "DTSTART:20260921T", $ics );
		$this->assertStringContainsString( 'ATTENDEE;CN="Mario Rossi"', $ics );
		$this->assertStringEndsWith( "END:VCALENDAR\r\n", $ics );
	}

	public function test_cancel_method_and_sequence(): void {
		$ics = ( new IcsBuilder() )->build( $this->data( array( 'method' => 'CANCEL', 'sequence' => 2 ) ) );
		$this->assertStringContainsString( "METHOD:CANCEL\r\n", $ics );
		$this->assertStringContainsString( "STATUS:CANCELLED\r\n", $ics );
		$this->assertStringContainsString( "SEQUENCE:2\r\n", $ics );
	}

	public function test_text_is_escaped(): void {
		$ics = ( new IcsBuilder() )->build( $this->data( array( 'description' => "Riga 1; con virgola, e\nriga 2" ) ) );
		$this->assertStringContainsString( 'DESCRIPTION:Riga 1\; con virgola\, e\nriga 2', $ics );
	}

	public function test_long_lines_are_folded_at_75_octets(): void {
		$ics = ( new IcsBuilder() )->build( $this->data( array( 'description' => str_repeat( 'àèìòù ', 40 ) ) ) );
		foreach ( explode( "\r\n", $ics ) as $line ) {
			$this->assertLessThanOrEqual( 75, strlen( $line ) );
		}
		// Dopo il dépiegamento il testo deve essere integro.
		$this->assertStringContainsString( str_repeat( 'àèìòù ', 40 ), str_replace( "\r\n ", '', $ics ) );
	}
}
