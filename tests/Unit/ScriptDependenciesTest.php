<?php
/**
 * Test di ScriptDependencies.
 *
 * @package Mavida\BookACall
 */

declare(strict_types=1);

namespace Mavida\BookACall\Tests\Unit;

use Mavida\BookACall\Blocks\ScriptDependencies;
use PHPUnit\Framework\TestCase;

/**
 * Verifica la raccolta delle dipendenze ricorsive degli script.
 */
final class ScriptDependenciesTest extends TestCase {

	/**
	 * Dipendenze reali del view script del blocco (WordPress 7.1).
	 *
	 * @return array<string, string[]>
	 */
	private function wordpress(): array {
		return array(
			'wpbac-booking-view-script' => array( 'react-jsx-runtime', 'wp-element', 'wp-i18n' ),
			'react-jsx-runtime'         => array( 'react' ),
			'wp-element'                => array( 'react', 'react-dom', 'wp-escape-html' ),
			'react-dom'                 => array( 'react' ),
			'react'                     => array( 'wp-polyfill' ),
			'wp-i18n'                   => array( 'wp-hooks' ),
			'wp-escape-html'            => array(),
			'wp-hooks'                  => array(),
			'wp-polyfill'               => array(),
			'jquery'                    => array(),
		);
	}

	public function test_collects_all_dependencies_recursively(): void {
		$result = ScriptDependencies::resolve( array( 'wpbac-booking-view-script' ), $this->wordpress() );
		sort( $result );

		$expected = array( 'react', 'react-dom', 'react-jsx-runtime', 'wp-element', 'wp-escape-html', 'wp-hooks', 'wp-i18n', 'wp-polyfill', 'wpbac-booking-view-script' );
		$this->assertSame( $expected, $result );
	}

	public function test_does_not_include_unrelated_scripts(): void {
		$this->assertNotContains( 'jquery', ScriptDependencies::resolve( array( 'wpbac-booking-view-script' ), $this->wordpress() ) );
	}

	public function test_shared_dependencies_appear_once(): void {
		$result = ScriptDependencies::resolve( array( 'wpbac-booking-view-script' ), $this->wordpress() );
		$this->assertSame( count( $result ), count( array_unique( $result ) ) );
	}

	public function test_survives_cycles_and_unknown_handles(): void {
		$result = ScriptDependencies::resolve( array( 'a', 'z' ), array( 'a' => array( 'b' ), 'b' => array( 'a' ) ) );
		$this->assertSame( array( 'a', 'z', 'b' ), $result );
	}

	public function test_empty_input_gives_empty_result(): void {
		$this->assertSame( array(), ScriptDependencies::resolve( array(), $this->wordpress() ) );
	}
}
