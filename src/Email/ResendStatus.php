<?php
/**
 * Stato dell'integrazione con Resend (attiva o in errore) e avvisi.
 *
 * @package Mavida\BookACall
 */

declare(strict_types=1);

namespace Mavida\BookACall\Email;

use Mavida\BookACall\Admin\AdminMenu;
use Mavida\BookACall\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Se Resend dà errore viene disattivato: le email usano WordPress, i promemoria WP-Cron,
 * il proprietario del sito riceve una email e l'admin mostra una notifica finché non si risolve.
 */
final class ResendStatus {

	/**
	 * Option con lo stato.
	 *
	 * @var string
	 */
	private const OPTION = 'wpbac_resend_state';

	/**
	 * Option con l'esito della verifica "la chiave può annullare".
	 *
	 * @var string
	 */
	private const CANCEL_OPTION = 'wpbac_resend_can_cancel';

	/**
	 * Aggancia la notifica in admin.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
	}

	/**
	 * È stata inserita una chiave API?
	 *
	 * @return bool
	 */
	public function has_key(): bool {
		return '' !== (string) Settings::get( 'resend_api_key' );
	}

	/**
	 * Stato salvato: status ok|error, message, since (timestamp), notified (email al proprietario già inviata).
	 *
	 * @return array{status:string,message:string,details:string,since:int,notified:bool}
	 */
	public function state(): array {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		return array(
			'status'   => 'error' === ( $saved['status'] ?? '' ) ? 'error' : 'ok',
			'message'  => (string) ( $saved['message'] ?? '' ),
			'details'  => (string) ( $saved['details'] ?? '' ),
			'since'    => (int) ( $saved['since'] ?? 0 ),
			'notified' => ! empty( $saved['notified'] ),
		);
	}

	/**
	 * Resend va usato? (chiave presente e nessun errore in corso)
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		return $this->has_key() && 'error' !== $this->state()['status'];
	}

	/**
	 * Registra un errore: disattiva Resend e, alla prima volta, avvisa il proprietario del sito.
	 *
	 * @param string $message      Descrizione dell'errore.
	 * @param bool   $notify_owner False per le prove manuali (l'utente vede già l'esito a schermo).
	 * @param string $details      Dati completi del tentativo (mittente, destinatari, risposta di Resend).
	 * @return void
	 */
	public function record_error( string $message, bool $notify_owner = true, string $details = '' ): void {
		$state     = $this->state();
		$new_issue = 'error' !== $state['status'];

		$notified = $state['notified'];
		if ( $notify_owner && ! $notified ) {
			$this->email_owner( $message, $details );
			$notified = true;
		}

		update_option(
			self::OPTION,
			array(
				'status'   => 'error',
				'message'  => $message,
				'details'  => $details,
				'since'    => $new_issue ? time() : $state['since'],
				'notified' => $notified,
			),
			false
		);

		if ( $new_issue ) {
			/**
			 * Resend è stato disattivato: i promemoria programmati vanno riportati a WP-Cron.
			 */
			do_action( 'wpbac_resend_disabled' );
		}
	}

	/**
	 * La chiave può annullare gli invii programmati? (sì finché non si verifica il contrario)
	 *
	 * @return bool
	 */
	public function can_cancel(): bool {
		return '0' !== (string) get_option( self::CANCEL_OPTION, '1' );
	}

	/**
	 * Memorizza se la chiave può annullare gli invii programmati.
	 *
	 * @param bool $can Esito della verifica.
	 * @return void
	 */
	public function set_can_cancel( bool $can ): void {
		update_option( self::CANCEL_OPTION, $can ? '1' : '0', false );
	}

	/**
	 * Il problema è risolto: Resend torna attivo.
	 *
	 * @return void
	 */
	public function clear(): void {
		delete_option( self::OPTION );
	}

	/**
	 * Notifica in admin: visibile agli amministratori finché Resend è in errore.
	 *
	 * @return void
	 */
	public function render_notice(): void {
		if ( ! current_user_can( 'manage_options' ) || ! $this->has_key() ) {
			return;
		}
		$state = $this->state();
		if ( 'error' !== $state['status'] ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s %3$s <a href="%4$s">%5$s</a></p></div>',
			esc_html__( 'Book a call:', 'wp-book-a-call' ),
			esc_html__( 'l\'invio delle email con Resend non funziona. Le email e i promemoria usano WordPress e WP-Cron finché il problema non è risolto.', 'wp-book-a-call' ),
			esc_html( '' !== $state['message'] ? '(' . $state['message'] . ')' : '' ),
			esc_url( admin_url( 'admin.php?page=' . AdminMenu::MENU_SLUG . '&tab=notifications' ) ),
			esc_html__( 'Apri le impostazioni', 'wp-book-a-call' )
		);
	}

	/**
	 * Email al proprietario del sito (con il normale invio di WordPress).
	 *
	 * @param string $message Errore di Resend.
	 * @param string $details Dati completi del tentativo (vuoto se non disponibili).
	 * @return void
	 */
	private function email_owner( string $message, string $details = '' ): void {
		$site = (string) get_bloginfo( 'name' );
		$body = "Ciao,\n\n"
			. "l'invio delle email tramite Resend sul sito {$site} ha dato un errore:\n\n{$message}\n\n"
			. ( '' !== $details ? "Dati del tentativo:\n{$details}\n\n" : '' )
			. "Cosa succede ora: Resend è stato disattivato in automatico. Finché il problema non è risolto le email di conferma, spostamento e annullamento partono dal server di WordPress e i promemoria vengono gestiti da WP-Cron. Nessuna prenotazione resta senza email.\n\n"
			. "Come risolvere: apri Book a call > Notifiche, controlla la chiave API e il dominio del mittente su resend.com e premi \"Riprova\":\n"
			. admin_url( 'admin.php?page=' . AdminMenu::MENU_SLUG . '&tab=notifications' ) . "\n";

		wp_mail( (string) get_option( 'admin_email' ), 'Book a call: Resend non funziona, uso il server di WordPress', $body );
	}
}
