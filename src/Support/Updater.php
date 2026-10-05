<?php
/**
 * Aggiornamento automatico del plugin da GitHub Releases.
 *
 * @package Mavida\BookACall
 */

declare(strict_types=1);

namespace Mavida\BookACall\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Registra plugin-update-checker, che confronta WPBAC_VERSION con l'ultima release su GitHub
 * e mostra l'aggiornamento nel pannello Plugin di WordPress.
 */
final class Updater {

	/**
	 * Repository GitHub del plugin.
	 *
	 * @var string
	 */
	private const REPO_URL = 'https://github.com/mavidasnc/wp-book-a-call/';

	/**
	 * Inizializza il controllo aggiornamenti.
	 *
	 * @return void
	 */
	public static function init(): void {
		if ( ! class_exists( '\YahnisElsts\PluginUpdateChecker\v5\PucFactory' ) ) {
			return;
		}

		$checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			self::REPO_URL,
			WPBAC_PLUGIN_FILE,
			'wp-book-a-call'
		);

		// Si installa lo zip allegato alla release (con vendor/ e build/), non il sorgente di GitHub.
		// @phpstan-ignore-next-line Il metodo esiste sull'API GitHub, ma il tipo restituito è generico.
		$checker->getVcsApi()->enableReleaseAssets();
	}
}
