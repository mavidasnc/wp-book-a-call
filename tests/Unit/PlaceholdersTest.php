<?php
/**
 * Test di Placeholders.
 *
 * @package Mavida\BookACall
 */

declare(strict_types=1);

namespace Mavida\BookACall\Tests\Unit;

use Mavida\BookACall\Support\Placeholders;
use PHPUnit\Framework\TestCase;

/**
 * Verifica la sostituzione dei segnaposto nei messaggi.
 */
final class PlaceholdersTest extends TestCase {

	public function test_known_placeholders_are_replaced(): void {
		$text = Placeholders::replace(
			'Ciao {name}, la call è {datetime} ({event}).',
			array(
				'name'     => 'Mario',
				'datetime' => 'giovedì 8 ottobre, 10:00',
				'event'    => 'Call conoscitiva',
			)
		);
		$this->assertSame( 'Ciao Mario, la call è giovedì 8 ottobre, 10:00 (Call conoscitiva).', $text );
	}

	public function test_same_placeholder_can_appear_more_than_once(): void {
		$this->assertSame( 'Mario e ancora Mario', Placeholders::replace( '{name} e ancora {name}', array( 'name' => 'Mario' ) ) );
	}

	public function test_unknown_placeholders_are_left_untouched(): void {
		$this->assertSame( 'Ciao {nome}', Placeholders::replace( 'Ciao {nome}', array( 'name' => 'Mario' ) ) );
	}

	public function test_values_are_not_interpreted_again(): void {
		// Un nome che contiene un segnaposto non deve essere espanso (niente sostituzioni a catena).
		$text = Placeholders::replace(
			'{name} {email}',
			array(
				'name'  => '{email}',
				'email' => 'a@b.it',
			)
		);
		$this->assertSame( '{email} a@b.it', $text );
	}

	public function test_text_without_placeholders_is_unchanged(): void {
		$this->assertSame( 'Nessun segnaposto qui.', Placeholders::replace( 'Nessun segnaposto qui.', array( 'name' => 'Mario' ) ) );
	}

	public function test_every_documented_placeholder_has_a_description(): void {
		foreach ( Placeholders::EMAIL as $key => $description ) {
			$this->assertMatchesRegularExpression( '/^[a-z_]+$/', $key );
			$this->assertNotSame( '', $description );
		}
	}
}
