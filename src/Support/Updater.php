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
	 * Pagina delle release (note di versione).
	 *
	 * @var string
	 */
	public const RELEASES_URL = 'https://github.com/mavidasnc/wp-book-a-call/releases';

	/**
	 * Checker creato da init().
	 *
	 * @var object|null
	 */
	private static ?object $checker = null;

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
		$checker->getVcsApi()->enableReleaseAssets();
		self::$checker = $checker;
	}

	/**
	 * Stato degli aggiornamenti secondo l'ultimo controllo (senza contattare GitHub).
	 *
	 * @return array{current:string,latest:string,update_available:bool,checked_at:int,releases_url:string}|null Null se l'updater non è disponibile.
	 */
	public static function status(): ?array {
		if ( null === self::$checker ) {
			return null;
		}

		$update = self::$checker->getUpdate();
		return array(
			'current'          => WPBAC_VERSION,
			'latest'           => $update ? (string) $update->version : WPBAC_VERSION,
			'update_available' => null !== $update,
			'checked_at'       => (int) self::$checker->getUpdateState()->getLastCheck(),
			'releases_url'     => self::RELEASES_URL,
		);
	}

	/**
	 * Interroga GitHub subito (ignorando l'intervallo di controllo) e restituisce lo stato.
	 *
	 * @return array{current:string,latest:string,update_available:bool,checked_at:int,releases_url:string}|null
	 */
	public static function check(): ?array {
		if ( null === self::$checker ) {
			return null;
		}
		self::$checker->checkForUpdates();
		return self::status();
	}
}
