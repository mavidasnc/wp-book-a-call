import { __, sprintf } from '@wordpress/i18n';
import { Button, Notice, Spinner, TextControl, TextareaControl, ToggleControl } from '@wordpress/components';
import { api } from '../api';
import Actions from '../components/Actions';
import Section from '../components/Section';
import useSettings from '../hooks/useSettings';

/** Esempio del JSON inviato al webhook. */
const WEBHOOK_EXAMPLE = `{
  "event": "booking.created",
  "sent_at": "2026-10-06T08:00:00+00:00",
  "site": "https://esempio.it",
  "booking": {
    "id": 12,
    "status": "confirmed",
    "start": "2026-10-08T08:00:00+00:00",
    "end": "2026-10-08T08:30:00+00:00",
    "name": "Mario Rossi",
    "email": "mario@example.com",
    "timezone": "Europe/Rome",
    "meet_url": "https://meet.google.com/abc-defg-hij",
    "answers": [ { "id": "q1", "question": "Di cosa vorresti parlare?", "answer": "..." } ],
    "source_url": "https://esempio.it/prenota-una-call/",
    "manage_url": "https://esempio.it/prenota-una-call/?wpbac_booking=12&wpbac_token=...",
    "created_at": "2026-10-06T07:59:30+00:00"
  },
  "event_type": { "id": 1, "slug": "call-conoscitiva", "title": "Call conoscitiva", "duration_min": 30 }
}`;

/** Scheda Notifiche: email al cliente e agli amministratori, promemoria e webhook. */
export default function Notifications() {
	const { s, set, notice, setNotice, saving, save, run } = useSettings();

	if ( ! s ) {
		return <Spinner />;
	}

	return (
		<div className="wpbac-admin__panel">
			{ notice && (
				<Notice status={ notice.status } onRemove={ () => setNotice( null ) }>
					{ notice.text }
				</Notice>
			) }

			<Section title={ __( 'Notifiche email', 'wp-book-a-call' ) } description={ __( 'Chi riceve la notifica a ogni prenotazione. L\'invito per il calendario (.ics) è allegato.', 'wp-book-a-call' ) }>
				<ToggleControl label={ __( 'Invia una email a ogni prenotazione', 'wp-book-a-call' ) } checked={ s.notify_enabled } onChange={ set( 'notify_enabled' ) } />
				<TextControl
					label={ __( 'Destinatari', 'wp-book-a-call' ) }
					help={ __( 'Più indirizzi separati da virgola.', 'wp-book-a-call' ) }
					value={ s.notify_recipients }
					onChange={ set( 'notify_recipients' ) }
				/>
				<div className="wpbac-admin__grid">
					<TextControl
						type="email"
						label={ __( 'Email del mittente', 'wp-book-a-call' ) }
						help={ sprintf(
							/* translators: %s: email di amministrazione del sito. */
							__( 'Se vuoto usa l\'email di amministrazione del sito (%s). Per non finire in spam deve appartenere a un dominio abilitato a spedire dal server (SPF/DKIM).', 'wp-book-a-call' ),
							s.admin_email
						) }
						placeholder={ s.admin_email }
						value={ s.from_email }
						onChange={ set( 'from_email' ) }
					/>
					<TextControl
						label={ __( 'Nome mostrato come organizzatore', 'wp-book-a-call' ) }
						help={ __( 'È anche il nome del mittente delle email.', 'wp-book-a-call' ) }
						value={ s.host_name }
						onChange={ set( 'host_name' ) }
					/>
				</div>
				<ToggleControl label={ __( 'Invia la conferma con .ics anche al cliente', 'wp-book-a-call' ) } checked={ s.client_email_enabled } onChange={ set( 'client_email_enabled' ) } />
				<TextareaControl
					label={ __( 'Messaggio di ringraziamento', 'wp-book-a-call' ) }
					help={ __( 'Compare all\'inizio dell\'email di conferma che il cliente riceve appena prenota.', 'wp-book-a-call' ) }
					value={ s.thanks_message }
					onChange={ set( 'thanks_message' ) }
					rows={ 3 }
				/>
				<ToggleControl
					label={ __( 'Promemoria al cliente 24 ore prima della call', 'wp-book-a-call' ) }
					help={ __( 'Include il link per spostare o annullare. Non parte per le call prenotate a meno di 24 ore dall\'inizio.', 'wp-book-a-call' ) }
					checked={ s.reminder_24h }
					onChange={ set( 'reminder_24h' ) }
				/>
				<ToggleControl label={ __( 'Promemoria al cliente 1 ora prima della call', 'wp-book-a-call' ) } checked={ s.reminder_1h } onChange={ set( 'reminder_1h' ) } />
				<Actions>
					<Button
						variant="secondary"
						onClick={ () => run( api( '/admin/settings/test-email', { method: 'POST' } ), __( 'Email di prova inviata.', 'wp-book-a-call' ) ) }
					>
						{ __( 'Invia email di prova', 'wp-book-a-call' ) }
					</Button>
				</Actions>
			</Section>

			<Section
				title="Webhook"
				description={ __( 'A ogni prenotazione creata, spostata o annullata il sito invia un POST JSON all\'indirizzo indicato: serve per collegare automazioni, ad esempio un workflow n8n.', 'wp-book-a-call' ) }
			>
				<TextControl type="url" label={ __( 'URL del webhook', 'wp-book-a-call' ) } value={ s.webhook_url } onChange={ set( 'webhook_url' ) } />
				<TextControl
					type="password"
					label={ __( 'Segreto per la firma', 'wp-book-a-call' ) }
					help={ s.webhook_secret_set ? __( 'Già salvato: compila solo per sostituirlo.', 'wp-book-a-call' ) : __( 'Facoltativo. Se presente, ogni richiesta ha l\'header X-Wpbac-Signature (HMAC SHA-256 del corpo).', 'wp-book-a-call' ) }
					value={ s.webhook_secret ?? '' }
					onChange={ set( 'webhook_secret' ) }
				/>
				<details className="wpbac-admin__guide">
					<summary>{ __( 'Cosa viene inviato', 'wp-book-a-call' ) }</summary>
					<p className="wpbac-admin__hint">{ __( 'Eventi: booking.created, booking.rescheduled, booking.cancelled. Il corpo contiene tutti i dati della prenotazione e del tipo di call:', 'wp-book-a-call' ) }</p>
					<pre className="wpbac-admin__code">{ WEBHOOK_EXAMPLE }</pre>
				</details>
				<Actions>
					<Button
						variant="secondary"
						disabled={ ! s.webhook_url }
						onClick={ () => run( api( '/admin/settings/test-webhook', { method: 'POST' } ), __( 'Webhook di prova inviato: il server ha risposto correttamente.', 'wp-book-a-call' ) ) }
					>
						{ __( 'Invia evento di prova', 'wp-book-a-call' ) }
					</Button>
				</Actions>
				<p className="wpbac-admin__hint">{ __( 'Salva le impostazioni prima di fare la prova.', 'wp-book-a-call' ) }</p>
			</Section>

			<div className="wpbac-admin__actions">
				<Button variant="primary" isBusy={ saving } disabled={ saving } onClick={ save }>
					{ saving ? __( 'Salvataggio…', 'wp-book-a-call' ) : __( 'Salva impostazioni', 'wp-book-a-call' ) }
				</Button>
			</div>
		</div>
	);
}
