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
use Mavida\BookACall\Support\Placeholders;
use Mavida\BookACall\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Notifica admin e cliente per prenotazione creata, spostata o annullata.
 * L'invio passa da Resend se attivo, altrimenti (o in caso di errore) da wp_mail.
 */
final class EmailSender {

	/**
	 * Costruttore.
	 *
	 * @param IcsBuilder   $ics    Generatore iCal.
	 * @param ResendClient $resend Client Resend.
	 */
	public function __construct(
		private readonly IcsBuilder $ics,
		private readonly ResendClient $resend
	) {}

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
		$reply_to = $admins ? array(
			'email' => $admins[0],
			'name'  => $host,
		) : null;

		// Email al cliente.
		if ( Settings::get( 'client_email_enabled' ) && is_email( $booking['email'] ) ) {
			$subjects = array(
				'created'     => 'Grazie per aver prenotato: %s',
				'rescheduled' => 'Prenotazione spostata: %s',
				'cancelled'   => 'Prenotazione annullata: %s',
			);
			$links    = array();
			if ( ! $cancel && '' !== $manage_url ) {
				$links['Sposta o annulla la prenotazione'] = $manage_url;
			}
			$this->deliver(
				$this->prepare(
					array( $booking['email'] ),
					sprintf( $subjects[ $kind ], $event_type['title'] ),
					array(
						'heading' => sprintf( 'Ciao %s', $booking['name'] ),
						'intro'   => $this->client_intro( $kind, $booking, $event_type, $manage_url ),
						'rows'    => $this->rows( $booking, $event_type, false ),
						'links'   => $links,
						'footer'  => $host,
					),
					$ics_file,
					$method,
					$reply_to
				)
			);
		}

		// Email agli amministratori.
		if ( Settings::get( 'notify_enabled' ) && $admins ) {
			$subjects = array(
				'created'     => 'Nuova prenotazione: %s',
				'rescheduled' => 'Prenotazione spostata: %s',
				'cancelled'   => 'Prenotazione annullata: %s',
			);
			$this->deliver(
				$this->prepare(
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
					array(
						'email' => (string) $booking['email'],
						'name'  => (string) $booking['name'],
					)
				)
			);
		}
	}

	/**
	 * Messaggio del promemoria, pronto per essere inviato subito o programmato su Resend.
	 *
	 * @param string              $kind       '24' (24 ore prima) o '1' (1 ora prima).
	 * @param array<string,mixed> $booking    Prenotazione.
	 * @param array<string,mixed> $event_type Tipo di call.
	 * @param string              $manage_url Link per spostare o annullare.
	 * @return array<string,mixed>|null Null se l'email del cliente non è valida.
	 */
	public function prepare_reminder( string $kind, array $booking, array $event_type, string $manage_url ): ?array {
		if ( ! is_email( $booking['email'] ) ) {
			return null;
		}

		$tz    = $this->timezone( (string) $booking['timezone'] );
		$admin = Settings::recipients();
		$links = '' !== $manage_url ? array( 'Sposta o annulla la prenotazione' => $manage_url ) : array();

		return $this->prepare(
			array( $booking['email'] ),
			sprintf( 'Promemoria: %s, %s', $event_type['title'], wp_date( 'j F \a\l\l\e H:i', $booking['start_ts'], $tz ) ),
			array(
				'heading' => sprintf( 'Ciao %s', $booking['name'] ),
				'intro'   => '1' === $kind ? 'Ti ricordo che la nostra call inizia tra circa un\'ora.' : 'Ti ricordo che la nostra call è fissata per domani, qui sotto trovi i dettagli.',
				'rows'    => $this->rows( $booking, $event_type, false ),
				'links'   => $links,
				'footer'  => Settings::host_name(),
			),
			'',
			'REQUEST',
			$admin ? array(
				'email' => $admin[0],
				'name'  => Settings::host_name(),
			) : null
		);
	}

	/**
	 * Promemoria al cliente, senza allegato (l'invito è già nel suo calendario).
	 *
	 * @param string              $kind       '24' (24 ore prima) o '1' (1 ora prima).
	 * @param array<string,mixed> $booking    Prenotazione.
	 * @param array<string,mixed> $event_type Tipo di call.
	 * @param string              $manage_url Link per spostare o annullare.
	 * @return bool
	 */
	public function send_reminder( string $kind, array $booking, array $event_type, string $manage_url ): bool {
		$prepared = $this->prepare_reminder( $kind, $booking, $event_type, $manage_url );
		return null !== $prepared && $this->deliver( $prepared );
	}

	/**
	 * Email di prova verso i destinatari configurati (usa il canale attivo).
	 *
	 * @return bool
	 */
	public function send_test(): bool {
		$admins = Settings::recipients();
		if ( ! $admins ) {
			return false;
		}
		return $this->deliver( $this->test_message( $admins ) );
	}

	/**
	 * Prova della chiave Resend: invia davvero attraverso Resend, senza ripiego su WordPress.
	 * Se riesce, rimuove lo stato di errore; se fallisce Resend resta disattivato.
	 *
	 * @return true|\WP_Error
	 */
	public function test_resend(): bool|\WP_Error {
		$admins = Settings::recipients();
		if ( ! $admins ) {
			return new \WP_Error( 'wpbac_no_recipient', __( 'Nessun destinatario configurato per la prova.', 'wp-book-a-call' ) );
		}

		$result = $this->resend->send( $this->test_message( $admins ), false );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$this->resend->status()->clear();
		$this->resend->status()->set_can_cancel( $this->resend->can_cancel() );
		return true;
	}

	/**
	 * Messaggio di prova.
	 *
	 * @param string[] $to Destinatari.
	 * @return array<string,mixed>
	 */
	private function test_message( array $to ): array {
		return $this->prepare(
			$to,
			'Email di prova: Book a call',
			array(
				'heading' => 'Email di prova',
				'intro'   => 'Le notifiche di Book a call funzionano correttamente.',
				'rows'    => array(),
				'links'   => array(),
				'footer'  => get_bloginfo( 'name' ),
			),
			'',
			'REQUEST',
			null
		);
	}

	/**
	 * Introduzione dell'email al cliente, dal messaggio personalizzato con i segnaposto sostituiti.
	 *
	 * @param string              $kind       created|rescheduled|cancelled.
	 * @param array<string,mixed> $booking    Prenotazione.
	 * @param array<string,mixed> $event_type Tipo di call.
	 * @param string              $manage_url Link di gestione.
	 * @return string
	 */
	private function client_intro( string $kind, array $booking, array $event_type, string $manage_url ): string {
		$keys    = array(
			'created'     => 'thanks_message',
			'rescheduled' => 'rescheduled_message',
			'cancelled'   => 'cancelled_message',
		);
		$message = Placeholders::replace( (string) Settings::get( $keys[ $kind ] ), $this->placeholder_values( $booking, $event_type, $manage_url ) );

		// Alla conferma si accoda la frase fissa su dettagli e invito.
		if ( 'created' === $kind ) {
			$message = trim( $message . ' La tua prenotazione è confermata: trovi i dettagli qui sotto e l\'invito per il calendario in allegato.' );
		}
		return $message;
	}

	/**
	 * Valori dei segnaposto per una prenotazione.
	 *
	 * @param array<string,mixed> $booking    Prenotazione.
	 * @param array<string,mixed> $event_type Tipo di call.
	 * @param string              $manage_url Link di gestione.
	 * @return array<string,string>
	 */
	private function placeholder_values( array $booking, array $event_type, string $manage_url ): array {
		$tz = $this->timezone( (string) $booking['timezone'] );
		return array(
			'name'       => (string) $booking['name'],
			'email'      => (string) $booking['email'],
			'date'       => wp_date( 'l j F Y', $booking['start_ts'], $tz ),
			'time'       => wp_date( 'H:i', $booking['start_ts'], $tz ),
			'datetime'   => wp_date( 'l j F Y, H:i', $booking['start_ts'], $tz ),
			'timezone'   => $tz->getName(),
			'event'      => (string) $event_type['title'],
			'duration'   => (string) $event_type['duration_min'],
			'host'       => Settings::host_name(),
			'meet_url'   => (string) $booking['meet_url'],
			'manage_url' => $manage_url,
			'site'       => (string) get_bloginfo( 'name' ),
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
	 * Costruisce il messaggio (HTML e testo già renderizzati), indipendente dal canale di invio.
	 *
	 * @param string[]                             $to       Destinatari.
	 * @param string                               $subject  Oggetto.
	 * @param array<string,mixed>                  $vars     Variabili del template.
	 * @param string                               $ics_file Contenuto .ics ('' = nessun allegato).
	 * @param string                               $method   Metodo iCal dell'allegato.
	 * @param array{email:string,name:string}|null $reply Indirizzo di risposta.
	 * @return array<string,mixed>
	 */
	private function prepare( array $to, string $subject, array $vars, string $ics_file, string $method, ?array $reply ): array {
		return array(
			'to'      => $to,
			'subject' => $subject,
			'html'    => $this->render( 'default-html', $vars ),
			'text'    => $this->render( 'default-text', $vars ),
			'ics'     => $ics_file,
			'method'  => $method,
			'reply'   => $reply,
		);
	}

	/**
	 * Consegna un messaggio: Resend se attivo, altrimenti (o se Resend fallisce) wp_mail.
	 *
	 * @param array<string,mixed> $message Messaggio di prepare().
	 * @return bool
	 */
	private function deliver( array $message ): bool {
		if ( $this->resend->is_active() && ! is_wp_error( $this->resend->send( $message ) ) ) {
			return true;
		}
		// Resend spento o appena fallito (ora disattivato e segnalato): la email parte comunque da WordPress.
		return $this->send_with_wp_mail( $message );
	}

	/**
	 * Invia con wp_mail: HTML, alternativa testo e allegato iCal opzionale.
	 *
	 * @param array<string,mixed> $message Messaggio di prepare().
	 * @return bool
	 */
	private function send_with_wp_mail( array $message ): bool {
		$text     = (string) $message['text'];
		$ics_file = (string) $message['ics'];
		$method   = (string) $message['method'];
		$headers  = array( 'Content-Type: text/html; charset=UTF-8' );
		if ( ! empty( $message['reply']['email'] ) ) {
			$headers[] = 'Reply-To: ' . $message['reply']['name'] . ' <' . $message['reply']['email'] . '>';
		}

		// Testo alternativo e allegato .ics direttamente su PHPMailer, senza file temporanei.
		$hook = static function ( $mailer ) use ( $text, $ics_file, $method ): void {
			$mailer->AltBody = $text; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Proprietà di PHPMailer.
			if ( '' !== $ics_file ) {
				$mailer->addStringAttachment( $ics_file, 'invito.ics', 'base64', 'text/calendar; charset=UTF-8; method=' . $method );
			}
		};
		add_action( 'phpmailer_init', $hook );

		// Mittente delle sole email del plugin (di default WordPress usa wordpress@dominio).
		$from_email = static fn(): string => Settings::from_email();
		$from_name  = static fn(): string => Settings::host_name();
		add_filter( 'wp_mail_from', $from_email, 99 );
		add_filter( 'wp_mail_from_name', $from_name, 99 );

		$sent = wp_mail( $message['to'], (string) $message['subject'], (string) $message['html'], $headers );

		remove_filter( 'wp_mail_from', $from_email, 99 );
		remove_filter( 'wp_mail_from_name', $from_name, 99 );
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
