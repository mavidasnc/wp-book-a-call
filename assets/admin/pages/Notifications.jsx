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

/** Provider di posta gratuiti: Resend non può spedire da questi domini (non si possono configurare i DNS). */
const FREE_MAIL_DOMAINS = [ 'gmail.com', 'googlemail.com', 'outlook.com', 'hotmail.com', 'live.com', 'yahoo.com', 'icloud.com', 'libero.it' ];

/** Scheda Notifiche: email al cliente e agli amministratori, promemoria e webhook. */
export default function Notifications() {
	const { s, set, notice, setNotice, saving, save, run } = useSettings();

	if ( ! s ) {
		return <Spinner />;
	}

	// Badge di stato di Resend.
	const resendBadge = ! s.resend_api_key_set
		? { className: 'is-cancelled', label: __( 'Non configurato (email da WordPress)', 'wp-book-a-call' ) }
		: s.resend?.active
			? { className: 'is-confirmed', label: __( 'Attivo', 'wp-book-a-call' ) }
			: { className: 'is-cancelled', label: __( 'In errore (email da WordPress)', 'wp-book-a-call' ) };

	// Mittente effettivo (campo dedicato, altrimenti email di amministrazione) e dominio gratuito?
	const senderEmail = s.from_email || s.admin_email || '';
	const senderFree = FREE_MAIL_DOMAINS.includes( senderEmail.split( '@' ).pop().toLowerCase() );

	// Come vengono inviati i promemoria e se il cron gira.
	const minutesAgo = s.reminders_last_run ? Math.round( ( Date.now() / 1000 - s.reminders_last_run ) / 60 ) : null;
	const reminderInfo = [
		s.resend?.active && s.resend.can_cancel
			? __( 'Promemoria: programmati su Resend appena arriva la prenotazione (fino a 30 giorni prima); WP-Cron resta come rete di sicurezza.', 'wp-book-a-call' )
			: __( 'Promemoria: inviati da WP-Cron, che controlla ogni 15 minuti.', 'wp-book-a-call' ),
		null === minutesAgo
			? __( 'Nessun controllo eseguito finora.', 'wp-book-a-call' )
			/* translators: %d: minuti trascorsi dall'ultimo controllo del cron. */
			: sprintf( __( 'Ultimo controllo: %d minuti fa.', 'wp-book-a-call' ), minutesAgo ),
	].join( ' ' );
	let cronWarning = '';
	if ( s.wp_cron_disabled ) {
		cronWarning = __( 'WP-Cron è disattivato (DISABLE_WP_CRON): serve un cron di sistema che richiami wp-cron.php, altrimenti i promemoria non partono.', 'wp-book-a-call' );
	} else if ( null !== minutesAgo && minutesAgo > 30 ) {
		cronWarning = __( 'Il controllo dei promemoria non gira da oltre 30 minuti: se il sito riceve poche visite WP-Cron può ritardare. Con una chiave Resend i promemoria non dipendono dal cron.', 'wp-book-a-call' );
	}

	return (
		<div className="wpbac-admin__panel">
			{ notice && (
				<Notice status={ notice.status } onRemove={ () => setNotice( null ) }>
					{ notice.text }
				</Notice>
			) }

			<Section
				title={ __( 'Invio email', 'wp-book-a-call' ) }
				description={ __( 'Di base le email partono dal server di WordPress. Con una chiave API di Resend partono da Resend (più affidabile) e i promemoria vengono programmati lì.', 'wp-book-a-call' ) }
			>
				<p className="wpbac-admin__status">
					{ __( 'Stato:', 'wp-book-a-call' ) }{ ' ' }
					<span className={ `wpbac-admin__badge ${ resendBadge.className }` }>{ resendBadge.label }</span>
				</p>
				<Notice status="info" isDismissible={ false }>
					{ __( 'Resend non funziona con indirizzi gmail.com (né altri provider gratuiti): serve una email con un dominio proprio. Il dominio va verificato su Resend configurando i DNS (record SPF e DKIM, meglio anche DMARC) in modo da autenticare l\'invio.', 'wp-book-a-call' ) }
				</Notice>
				{ s.resend_api_key_set && senderFree && (
					<Notice status="warning" isDismissible={ false }>
						{ sprintf(
							/* translators: %s: indirizzo del mittente. */
							__( 'Il mittente attuale (%s) usa un dominio gratuito: Resend rifiuterà l\'invio e le email passeranno da WordPress. Imposta un\'email del tuo dominio in "Email del mittente".', 'wp-book-a-call' ),
							senderEmail
						) }
					</Notice>
				) }
				{ s.resend?.active && ! s.resend.can_cancel && (
					<Notice status="warning" isDismissible={ false }>
						{ __( 'La chiave è limitata all\'invio e non può annullare i promemoria programmati: i promemoria restano gestiti da WP-Cron. Per programmarli su Resend usa una chiave con accesso completo.', 'wp-book-a-call' ) }
					</Notice>
				) }
				{ s.resend?.error && (
					<Notice status="error" isDismissible={ false }>
						{ sprintf(
							/* translators: %s: errore restituito da Resend. */
							__( 'Resend non funziona e per ora è disattivato: email e promemoria usano WordPress e WP-Cron. Errore: %s', 'wp-book-a-call' ),
							s.resend.error
						) }
					</Notice>
				) }
				<TextControl
					type="password"
					label={ __( 'Chiave API di Resend', 'wp-book-a-call' ) }
					help={ s.resend_api_key_set ? __( 'Già salvata: compila solo per sostituirla. Quando salvi, il plugin invia una email di prova.', 'wp-book-a-call' ) : __( 'Crea la chiave su resend.com. L\'email del mittente deve appartenere a un dominio verificato su Resend.', 'wp-book-a-call' ) }
					value={ s.resend_api_key ?? '' }
					onChange={ set( 'resend_api_key' ) }
				/>
				{ s.resend_api_key_set && (
					<Actions>
						<Button variant="secondary" onClick={ () => run( api( '/admin/resend/test', { method: 'POST' } ), __( 'Resend riattivato.', 'wp-book-a-call' ) ) }>
							{ s.resend?.error ? __( 'Riprova', 'wp-book-a-call' ) : __( 'Prova la chiave', 'wp-book-a-call' ) }
						</Button>
						<Button isDestructive variant="tertiary" onClick={ () => run( api( '/admin/resend/remove', { method: 'POST' } ), __( 'Chiave rimossa: le email usano WordPress.', 'wp-book-a-call' ) ) }>
							{ __( 'Rimuovi la chiave', 'wp-book-a-call' ) }
						</Button>
					</Actions>
				) }
			</Section>

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
					label={ __( 'Messaggio di conferma (ringraziamento)', 'wp-book-a-call' ) }
					help={ __( 'Compare all\'inizio dell\'email che il cliente riceve appena prenota.', 'wp-book-a-call' ) }
					value={ s.thanks_message }
					onChange={ set( 'thanks_message' ) }
					rows={ 3 }
				/>
				<TextareaControl
					label={ __( 'Messaggio per prenotazione spostata', 'wp-book-a-call' ) }
					help={ __( 'Compare all\'inizio dell\'email che il cliente riceve quando la call viene spostata.', 'wp-book-a-call' ) }
					value={ s.rescheduled_message }
					onChange={ set( 'rescheduled_message' ) }
					rows={ 3 }
				/>
				<TextareaControl
					label={ __( 'Messaggio per prenotazione annullata', 'wp-book-a-call' ) }
					help={ __( 'Compare all\'inizio dell\'email che il cliente riceve quando la call viene annullata.', 'wp-book-a-call' ) }
					value={ s.cancelled_message }
					onChange={ set( 'cancelled_message' ) }
					rows={ 3 }
				/>
				<details className="wpbac-admin__guide">
					<summary>{ __( 'Segnaposto utilizzabili nei messaggi', 'wp-book-a-call' ) }</summary>
					<ul className="wpbac-admin__placeholders">
						{ Object.entries( s.email_placeholders ?? {} ).map( ( [ key, description ] ) => (
							<li key={ key }>
								<code>{ `{${ key }}` }</code> { description }
							</li>
						) ) }
					</ul>
				</details>
				<ToggleControl
					label={ __( 'Promemoria al cliente 24 ore prima della call', 'wp-book-a-call' ) }
					help={ __( 'Include il link per spostare o annullare. Non parte per le call prenotate a meno di 24 ore dall\'inizio.', 'wp-book-a-call' ) }
					checked={ s.reminder_24h }
					onChange={ set( 'reminder_24h' ) }
				/>
				<ToggleControl label={ __( 'Promemoria al cliente 1 ora prima della call', 'wp-book-a-call' ) } checked={ s.reminder_1h } onChange={ set( 'reminder_1h' ) } />
				<p className="wpbac-admin__hint">{ reminderInfo }</p>
				{ cronWarning && (
					<Notice status="warning" isDismissible={ false }>
						{ cronWarning }
					</Notice>
				) }
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
