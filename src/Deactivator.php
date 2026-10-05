<?php
/**
 * Disattivazione del plugin.
 *
 * @package Mavida\BookACall
 */

declare(strict_types=1);

namespace Mavida\BookACall;

use Mavida\BookACall\Reminders\ReminderService;

defined( 'ABSPATH' ) || exit;

/**
 * Toglie gli eventi cron: i dati restano (si cancellano solo alla disinstallazione, se richiesto).
 */
final class Deactivator {

	/**
	 * Rimuove l'evento dei promemoria.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( ReminderService::HOOK );
	}
}
