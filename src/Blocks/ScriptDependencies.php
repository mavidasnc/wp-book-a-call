<?php
/**
 * Dipendenze ricorsive di uno o più script registrati.
 *
 * @package Mavida\BookACall
 */

declare(strict_types=1);

namespace Mavida\BookACall\Blocks;

/**
 * Logica pura, senza WordPress: riceve la mappa handle => dipendenze dirette.
 */
final class ScriptDependencies {

	/**
	 * Gli handle indicati più tutte le loro dipendenze, a qualunque profondità, senza doppioni.
	 * Gli handle non presenti nella mappa restano nel risultato ma non hanno dipendenze.
	 *
	 * @param string[]                $handles    Handle di partenza.
	 * @param array<string, string[]> $dependency Dipendenze dirette per handle.
	 * @return string[]
	 */
	public static function resolve( array $handles, array $dependency ): array {
		$seen  = array();
		$queue = $handles;

		while ( $queue ) {
			$handle = array_shift( $queue );
			if ( isset( $seen[ $handle ] ) ) {
				continue;
			}
			$seen[ $handle ] = true;
			foreach ( $dependency[ $handle ] ?? array() as $dep ) {
				$queue[] = $dep;
			}
		}

		return array_keys( $seen );
	}
}
