<?php
/**
 * Test del corpo JSON inviato a Resend.
 *
 * @package Mavida\BookACall
 */

declare(strict_types=1);

namespace Mavida\BookACall\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * ResendClient contiene accessi a WordPress, quindi la classe viene caricata con una costante ABSPATH di prova.
 */
final class ResendPayloadTest extends TestCase {

	public static function setUpBeforeClass(): void {
		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', __DIR__ . '/' );
		}
		require_once dirname( __DIR__, 2 ) . '/src/Email/ResendClient.php';
	}

	/**
	 * Messaggio di base.
	 *
	 * @param array<string,mixed> $override Valori da sovrascrivere.
	 * @return array<string,mixed>
	 */
	private function message( array $override = array() ): array {
		return array_merge(
			array(
				'from_email' => 'maurizio@mavida.com',
				'from_name'  => 'Maurizio Pelizzone',
				'to'         => array( 'cliente@example.com' ),
				'subject'    => 'Conferma',
				'html'       => '<p>Ciao</p>',
				'text'       => 'Ciao',
			),
			$override
		);
	}

	public function test_basic_fields(): void {
		$payload = \Mavida\BookACall\Email\ResendClient::build_payload( $this->message() );
		$this->assertSame( 'Maurizio Pelizzone <maurizio@mavida.com>', $payload['from'] );
		$this->assertSame( array( 'cliente@example.com' ), $payload['to'] );
		$this->assertSame( 'Conferma', $payload['subject'] );
		$this->assertArrayNotHasKey( 'attachments', $payload );
		$this->assertArrayNotHasKey( 'scheduled_at', $payload );
		$this->assertArrayNotHasKey( 'reply_to', $payload );
	}

	public function test_from_name_is_cleaned(): void {
		$payload = \Mavida\BookACall\Email\ResendClient::build_payload( $this->message( array( 'from_name' => 'Mario "Il <Boss>"' ) ) );
		$this->assertSame( 'Mario Il Boss <maurizio@mavida.com>', $payload['from'] );
	}

	public function test_empty_name_uses_the_bare_address(): void {
		$payload = \Mavida\BookACall\Email\ResendClient::build_payload( $this->message( array( 'from_name' => '' ) ) );
		$this->assertSame( 'maurizio@mavida.com', $payload['from'] );
	}

	public function test_reply_to_is_a_list(): void {
		$payload = \Mavida\BookACall\Email\ResendClient::build_payload(
			$this->message(
				array(
					'reply' => array(
						'email' => 'info@mavida.com',
						'name'  => 'Maurizio',
					),
				)
			)
		);
		$this->assertSame( array( 'info@mavida.com' ), $payload['reply_to'] );
	}

	public function test_ics_attachment_is_base64_with_method(): void {
		$payload = \Mavida\BookACall\Email\ResendClient::build_payload(
			$this->message(
				array(
					'ics'    => "BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n",
					'method' => 'CANCEL',
				)
			)
		);
		$this->assertSame( 'invito.ics', $payload['attachments'][0]['filename'] );
		$this->assertSame( "BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n", base64_decode( $payload['attachments'][0]['content'], true ) );
		$this->assertStringContainsString( 'method=CANCEL', $payload['attachments'][0]['content_type'] );
	}

	public function test_key_is_masked(): void {
		$this->assertSame( 're_…wxyz (21 caratteri)', \Mavida\BookACall\Email\ResendClient::mask_key( 're_abcdefghijklmnwxyz' ) );
		$this->assertSame( '(nessuna chiave salvata)', \Mavida\BookACall\Email\ResendClient::mask_key( '' ) );
	}

	public function test_diagnostic_shows_sender_source_and_response(): void {
		$text = \Mavida\BookACall\Email\ResendClient::build_diagnostic(
			$this->message(),
			array(
				'key_hint'    => 're_…wxyz (20 caratteri)',
				'from_source' => 'admin',
				'saved_from'  => '',
				'admin_email' => 'maurizio@mavida.com',
				'endpoint'    => 'POST https://api.resend.com/emails',
				'http'        => 403,
				'response'    => '{"message":"The mavida.com domain is not verified."}',
				'site'        => 'https://maurizio.mavida.com',
				'versions'    => 'plugin 0.8.1',
				'time'        => '2026-10-07 08:00:00 UTC',
			)
		);
		$this->assertStringContainsString( 'Mittente usato nel test: Maurizio Pelizzone <maurizio@mavida.com>', $text );
		$this->assertStringContainsString( 'Dominio del mittente: mavida.com', $text );
		$this->assertStringContainsString( 'email di amministrazione di WordPress', $text );
		$this->assertStringContainsString( 'Valore salvato nel campo "Email del mittente": (vuoto)', $text );
		$this->assertStringContainsString( 'Risposta di Resend: HTTP 403 {"message"', $text );
	}

	public function test_scheduled_at_is_passed_through(): void {
		$payload = \Mavida\BookACall\Email\ResendClient::build_payload( $this->message( array( 'scheduled_at' => '2026-10-07T08:00:00+00:00' ) ) );
		$this->assertSame( '2026-10-07T08:00:00+00:00', $payload['scheduled_at'] );
	}
}
