<?php
/**
 * Stato di una prenotazione.
 *
 * @package Mavida\BookACall
 */

declare(strict_types=1);

namespace Mavida\BookACall\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Stati possibili: lo spostamento modifica la prenotazione senza cambiarne lo stato.
 */
enum BookingStatus: string {
	case Confirmed = 'confirmed';
	case Cancelled = 'cancelled';
}
