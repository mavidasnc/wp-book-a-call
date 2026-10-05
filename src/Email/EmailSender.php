<?php
/**
 * Invio delle email di prenotazione con allegato iCal.
 *
 * @package Mavida\BookACall
 */

declare(strict_types=1);

namespace Mavida\BookACall\Email;

use DateTimeZone;
use Mavida\BookACall\Calendar\IcsBuilder;
use Mavida\BookACall\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Notifica admin e cliente per prenotazione creata, spostata o annullata.
 */
final class EmailSender {

	/**
	 * Costruttore.
	 *
	 * @param IcsBuilder $ics Generatore iCal.
	 */
	public function __construct( private readonly IcsBuilder $ics ) {}

	/**
	 * Invia le email per un evento di prenotazione.
	 *
	 * @param string              $kind       created|rescheduled|cancelled.
	 * @param array<string,mixed> $booking    Prenotazione.
	 * @param array<string,mixed> $event_type Tipo di call.
	 * @param string              $manage_url Link di gestione per il cliente (vuoto se non disponibile).
	 * @return void
	 */
	public function send_booking_email( string $kind, array $booking, array $event_type, string $manage_url ): void {
		$cancel   = 'cancelled' === $kind;
		$ics_file = $this->ics->build( $this->ics_data( $booking, $event_type, $cancel ) );
		$method   = $cancel ? 'CANCEL' : 'REQUEST';
		$host     = Settings::host_name();
		$admins   = Settings::recipients();
		$reply_to = $admins ? $admins[0] : '';

		// Email al cliente.
		if ( Settings::get( 'client_email_enabled' ) && is_email( $booking['email'] ) ) {
			$subjects = array(
				'created'     => 'Prenotazione confermata: %s',
				'rescheduled' => 'Prenotazione spostata: %s',
				'cancelled'   => 'Prenotazione annullata: %s',
			);
			$intro    = array(
				'created'     => 'La tua prenotazione è confermata. Trovi i dettagli qui sotto e l\'invito per il calendario in allegato.',
				'rescheduled' => 'La tua prenotazione è stata spostata. Trovi i nuovi dettagli qui sotto e l\'invito aggiornato in allegato.',
				'cancelled'   => 'La tua prenotazione è stata annullata.',
			);
			$links    = array();
			if ( ! $cancel && '' !== $manage_url ) {
				$links['Sposta o annulla la prenotazione'] = $manage_url;
			}
			$this->send(
				array( $booking['email'] ),
				sprintf( $subjects[ $kind ], $event_type['title'] ),
				array(
					'heading' => sprintf( 'Ciao %s', $booking['name'] ),
					'intro'   => $intro[ $kind ],
					'rows'    => $this->rows( $booking, $event_type, false ),
					'links'   => $links,
					'footer'  => $host,
				),
				$ics_file,
				$method,
				$reply_to ? array( 'Reply-To: ' . $host . ' <' . $reply_to . '>' ) : array()
			);
		}

		// Email agli amministratori.
		if ( Settings::get( 'notify_enabled' ) && $admins ) {
			$subjects = array(
				'created'     => 'Nuova prenotazione: %s',
				'rescheduled' => 'Prenotazione spostata: %s',
				'cancelled'   => 'Prenotazione annullata: %s',
			);
			$this->send(
				$admins,
				sprintf( $subjects[ $kind ], $booking['name'] ),
				array(
					'heading' => sprintf( $subjects[ $kind ], $event_type['title'] ),
					'intro'   => sprintf( '%s (%s)', $booking['name'], $booking['email'] ),
					'rows'    => $this->rows( $booking, $event_type, true ),
					'links'   => array(),
					'footer'  => get_bloginfo( 'name' ),
				),
				// Se l'evento è già nel calendario Google l'allegato creerebbe un duplicato.
				'' === $booking['google_event_id'] ? $ics_file : '',
				$method,
				array( 'Reply-To: ' . $booking['name'] . ' <' . $booking['email'] . '>' )
			);
		}
	}

	/**
	 * Email di prova verso i destinatari configurati.
	 *
	 * @return bool
	 */
	public function send_test(): bool {
		$admins = Settings::recipients();
		if ( ! $admins ) {
			return false;
		}
		return $this->send(
			$admins,
			'Email di prova: WP Book a Call',
			array(
				'heading' => 'Email di prova',
				'intro'   => 'Le notifiche di WP Book a Call funzionano correttamente.',
				'rows'    => array(),
				'links'   => array(),
				'footer'  => get_bloginfo( 'name' ),
			),
			'',
			'REQUEST',
			array()
		);
	}

	/**
	 * Righe di dettaglio dell'email.
	 *
	 * @param array<string,mixed> $booking    Prenotazione.
	 * @param array<string,mixed> $event_type Tipo di call.
	 * @param bool                $for_admin  Aggiunge risposte alle domande e fuso del sito.
	 * @return array<string,string>
	 */
	private function rows( array $booking, array $event_type, bool $for_admin ): array {
		$tz   = $this->timezone( (string) $booking['timezone'] );
		$rows = array(
			'Evento' => (string) $event_type['title'],
			'Quando' => wp_date( 'l j F Y, H:i', $booking['start_ts'], $tz ) . ' (' . $tz->getName() . ')',
			'Durata' => $event_type['duration_min'] . ' minuti',
		);

		if ( $for_admin && $tz->getName() !== wp_timezone_string() ) {
			$rows['Quando (fuso del sito)'] = wp_date( 'l j F Y, H:i', $booking['start_ts'] ) . ' (' . wp_timezone_string() . ')';
		}

		$where = $this->location( $booking, $event_type );
		if ( '' !== $where ) {
			$rows['Dove'] = $where;
		}

		if ( $for_admin ) {
			foreach ( (array) $event_type['questions'] as $question ) {
				$answer = (string) ( $booking['answers'][ $question['id'] ] ?? '' );
				if ( '' !== $answer ) {
					$rows[ $question['label'] ] = $answer;
				}
			}
		}
		return $rows;
	}

	/**
	 * Luogo della call: link Meet, altrimenti il valore configurato.
	 *
	 * @param array<string,mixed> $booking    Prenotazione.
	 * @param array<string,mixed> $event_type Tipo di call.
	 * @return string
	 */
	private function location( array $booking, array $event_type ): string {
		if ( '' !== $booking['meet_url'] ) {
			return (string) $booking['meet_url'];
		}
		return (string) $event_type['location_value'];
	}

	/**
	 * Dati per IcsBuilder.
	 *
	 * @param array<string,mixed> $booking    Prenotazione.
	 * @param array<string,mixed> $event_type Tipo di call.
	 * @param bool                $cancel     True per METHOD:CANCEL.
	 * @return array<string,mixed>
	 */
	private function ics_data( array $booking, array $event_type, bool $cancel ): array {
		$admins      = Settings::recipients();
		$description = array();
		foreach ( (array) $event_type['questions'] as $question ) {
			if ( ! empty( $booking['answers'][ $question['id'] ] ) ) {
				$description[] = $question['label'] . ': ' . $booking['answers'][ $question['id'] ];
			}
		}
		if ( '' !== $booking['meet_url'] ) {
			$description[] = 'Google Meet: ' . $booking['meet_url'];
		}

		return array(
			'uid'             => 'wpbac-' . $booking['id'] . '@' . wp_parse_url( home_url(), PHP_URL_HOST ),
			'start_ts'        => $booking['start_ts'],
			'end_ts'          => $booking['end_ts'],
			'summary'         => $event_type['title'] . ' - ' . Settings::host_name(),
			'description'     => implode( "\n", $description ),
			'location'        => $this->location( $booking, $event_type ),
			'organizer_name'  => Settings::host_name(),
			'organizer_email' => $admins ? $admins[0] : get_option( 'admin_email' ),
			'attendee_name'   => $booking['name'],
			'attendee_email'  => $booking['email'],
			'sequence'        => $booking['ics_sequence'],
			'method'          => $cancel ? 'CANCEL' : 'REQUEST',
		);
	}

	/**
	 * Fuso del cliente, con fallback sul fuso del sito.
	 *
	 * @param string $name Identificatore IANA.
	 * @return DateTimeZone
	 */
	private function timezone( string $name ): DateTimeZone {
		try {
			return new DateTimeZone( $name );
		} catch ( \Exception $e ) {
			return wp_timezone();
		}
	}

	/**
	 * Invia una email HTML con alternativa testo e allegato iCal opzionale.
	 *
	 * @param string[]            $to       Destinatari.
	 * @param string              $subject  Oggetto.
	 * @param array<string,mixed> $vars     Variabili del template.
	 * @param string              $ics_file Contenuto .ics ('' = nessun allegato).
	 * @param string              $method   Metodo iCal dell'allegato.
	 * @param string[]            $headers  Header aggiuntivi.
	 * @return bool
	 */
	private function send( array $to, string $subject, array $vars, string $ics_file, string $method, array $headers ): bool {
		$html = $this->render( 'default-html', $vars );
		$text = $this->render( 'default-text', $vars );

		// Testo alternativo e allegato .ics direttamente su PHPMailer, senza file temporanei.
		$hook = static function ( $mailer ) use ( $text, $ics_file, $method ): void {
			$mailer->AltBody = $text; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Proprietà di PHPMailer.
			if ( '' !== $ics_file ) {
				$mailer->addStringAttachment( $ics_file, 'invito.ics', 'base64', 'text/calendar; charset=UTF-8; method=' . $method );
			}
		};
		add_action( 'phpmailer_init', $hook );

		$headers[] = 'Content-Type: text/html; charset=UTF-8';
		$sent      = wp_mail( $to, $subject, $html, $headers );

		remove_action( 'phpmailer_init', $hook );
		return $sent;
	}

	/**
	 * Renderizza un template, con override opzionale nel tema (wp-book-a-call/email/).
	 *
	 * @param string              $name Nome del template senza estensione.
	 * @param array<string,mixed> $vars Variabili.
	 * @return string
	 */
	private function render( string $name, array $vars ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- $vars è letto dal template incluso.
		$file = locate_template( 'wp-book-a-call/email/' . $name . '.php' );
		if ( '' === $file ) {
			$file = WPBAC_PLUGIN_DIR . 'templates/email/' . $name . '.php';
		}

		ob_start();
		include $file;
		return (string) ob_get_clean();
	}
}
