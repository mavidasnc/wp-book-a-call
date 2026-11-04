<?php
/**
 * Registrazione dei blocchi Gutenberg.
 *
 * @package Mavida\BookACall
 */

declare(strict_types=1);

namespace Mavida\BookACall\Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * Registra i blocchi compilati in build/.
 */
final class BlockRegistrar {

	/**
	 * Attributi con cui i plugin di ottimizzazione (LiteSpeed, ...) lasciano uno script com'è:
	 * niente rinvio all'interazione, niente accorpamento con altri file.
	 */
	private const NO_OPTIMIZE_ATTRS = ' data-no-defer="1" data-no-optimize="1"';

	/**
	 * Handle da proteggere, calcolati alla prima richiesta.
	 *
	 * @var string[]|null
	 */
	private ?array $protected = null;

	/**
	 * Aggancia gli hook.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_blocks' ) );
		add_filter( 'script_loader_tag', array( $this, 'protect_view_script' ), 10, 2 );
	}

	/**
	 * Registra il blocco di prenotazione da build/booking/block.json.
	 *
	 * @return void
	 */
	public function register_blocks(): void {
		register_block_type( WPBAC_PLUGIN_DIR . 'build/booking' );
	}

	/**
	 * Il widget legge React, wp-element e jsx-runtime appena view.js viene eseguito. Se un plugin di
	 * ottimizzazione rinvia o accorpa gli script cambiandone l'ordine (LiteSpeed in Guest Mode, 4/11/2026),
	 * view.js parte prima delle dipendenze e il modulo resta vuoto: un nuovo tentativo dopo non basta,
	 * perché la build legge le dipendenze una volta sola. Si marcano quindi view.js e tutte le sue
	 * dipendenze, compresi gli script inline collegati (traduzioni), perché restino nell'ordine di WordPress.
	 *
	 * @param string $tag    HTML del tag (o dei tag) dello script.
	 * @param string $handle Handle dello script.
	 * @return string
	 */
	public function protect_view_script( string $tag, string $handle ): string {
		if ( str_contains( $tag, 'data-no-defer' ) || ! in_array( $handle, $this->protected_handles(), true ) ) {
			return $tag;
		}

		return str_replace( '<script', '<script' . self::NO_OPTIMIZE_ATTRS, $tag );
	}

	/**
	 * View script del blocco con tutte le dipendenze registrate.
	 *
	 * @return string[]
	 */
	private function protected_handles(): array {
		if ( null !== $this->protected ) {
			return $this->protected;
		}

		$block = \WP_Block_Type_Registry::get_instance()->get_registered( 'wpbac/booking' );
		if ( ! $block ) {
			return array();
		}

		$dependency = array();
		foreach ( wp_scripts()->registered as $name => $script ) {
			$dependency[ $name ] = $script->deps;
		}

		$this->protected = ScriptDependencies::resolve( $block->view_script_handles, $dependency );

		return $this->protected;
	}
}
