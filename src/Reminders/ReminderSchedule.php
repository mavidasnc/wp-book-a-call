<?php
/**
 * Calcolo degli invii da programmare su Resend (logica pura, senza dipendenze da WordPress).
 *
 * @package Mavida\BookACall
 */

declare(strict_types=1);

namespace Mavida\BookACall\Reminders;

/**
 * Decide quali promemoria si possono programmare e a che ora.
 */
final class ReminderSchedule {

	/**
	 * Anticipo di ciascun promemoria rispetto all'inizio della call, in secondi.
	 *
	 * @var array<int,int>
	 */
	public const OFFSETS = array(
		'24' => 86400,
		'1'  => 3600,
	);

	/**
	 * Margine minimo tra "adesso" e l'invio programmato (Resend accetta solo date future).
	 *
	 * @var int
	 */
	public const MIN_LEAD = 120;

	/**
	 * Orizzonte massimo: Resend programma fino a 30 giorni, qui 29 per sicurezza.
	 *
	 * @var int
	 */
	public const MAX_AHEAD = 29 * 86400;

	/**
	 * Promemoria programmabili adesso.
	 *
	 * @param int  $start_ts Inizio della call (UNIX).
	 * @param int  $now      Timestamp corrente.
	 * @param bool $want_24  Promemoria a 24 ore attivo.
	 * @param bool $want_1   Promemoria a 1 ora attivo.
	 * @return array<int,int> Tipo (24|1) => timestamp di invio.
	 */
	public static function plan( int $start_ts, int $now, bool $want_24, bool $want_1 ): array {
		$wanted = array(
			'24' => $want_24,
			'1'  => $want_1,
		);

		$plan = array();
		foreach ( self::OFFSETS as $kind => $offset ) {
			$send_ts = $start_ts - $offset;
			if ( $wanted[ $kind ] && $send_ts >= $now + self::MIN_LEAD && $send_ts <= $now + self::MAX_AHEAD ) {
				$plan[ $kind ] = $send_ts;
			}
		}
		return $plan;
	}
}
