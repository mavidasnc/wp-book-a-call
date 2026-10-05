<?php
/**
 * Test di CsvBuilder.
 *
 * @package Mavida\BookACall
 */

declare(strict_types=1);

namespace Mavida\BookACall\Tests\Unit;

use Mavida\BookACall\Export\CsvBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Verifica BOM, separatore, virgolette e protezione dalle formule.
 */
final class CsvBuilderTest extends TestCase {

	public function test_starts_with_utf8_bom_and_uses_semicolon(): void {
		$csv = CsvBuilder::build( array( 'A', 'B' ), array( array( 1, 'x' ) ) );
		$this->assertStringStartsWith( "\xEF\xBB\xBF", $csv );
		$this->assertSame( "A;B\r\n1;x\r\n", substr( $csv, 3 ) );
	}

	public function test_cells_with_delimiter_quotes_or_newlines_are_quoted(): void {
		$csv = CsvBuilder::build( array( 'Nota' ), array( array( 'a;b' ), array( 'dice "ciao"' ), array( "riga1\nriga2" ) ) );
		$this->assertStringContainsString( "\"a;b\"\r\n", $csv );
		$this->assertStringContainsString( "\"dice \"\"ciao\"\"\"\r\n", $csv );
		$this->assertStringContainsString( "\"riga1\nriga2\"\r\n", $csv );
	}

	public function test_formula_injection_is_neutralised(): void {
		$csv = CsvBuilder::build( array( 'Nome' ), array( array( '=HYPERLINK("http://x")' ), array( '+39 333 1234567' ), array( '@cmd' ), array( '-1+1' ) ) );
		$this->assertStringContainsString( "'=HYPERLINK", $csv );
		$this->assertStringContainsString( "'+39 333 1234567", $csv );
		$this->assertStringContainsString( "'@cmd", $csv );
		$this->assertStringContainsString( "'-1+1", $csv );
	}

	public function test_accents_and_empty_values_are_kept(): void {
		$csv = CsvBuilder::build( array( 'Nome', 'Nota' ), array( array( 'Niccolò', null ) ) );
		$this->assertStringContainsString( "Niccolò;\r\n", $csv );
	}

	public function test_custom_delimiter(): void {
		$csv = CsvBuilder::build( array( 'A', 'B' ), array( array( 'x;y', 'z' ) ), ',' );
		$this->assertStringContainsString( "x;y,z\r\n", $csv );
	}
}
