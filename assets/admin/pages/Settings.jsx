import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Button, Notice, Spinner, TextControl, TextareaControl, ToggleControl } from '@wordpress/components';
import { api } from '../api';
import Actions from '../components/Actions';
import GoogleGuide from '../components/GoogleGuide';
import Section from '../components/Section';

/** Messaggio comprensibile per il motivo restituito dal ritorno da Google. */
const googleError = ( reason ) => {
	const messages = {
		access_denied: __( 'Hai negato il consenso su Google. Riprova e premi "Consenti".', 'wp-book-a-call' ),
		invalid_client: __( 'Client ID o Client secret non corretti: ricopiali dalla Google Cloud Console.', 'wp-book-a-call' ),
		redirect_uri_mismatch: __( 'L\'URI di reindirizzamento non è tra quelli autorizzati nel client OAuth: copia quello indicato nella guida.', 'wp-book-a-call' ),
		invalid_grant: __( 'Il codice di autorizzazione è scaduto o già usato: riprova.', 'wp-book-a-call' ),
		no_refresh_token: __( 'Google non ha restituito il token di aggiornamento. Revoca l\'accesso dell\'app su myaccount.google.com/permissions e riprova.', 'wp-book-a-call' ),
		network: __( 'Il server non riesce a contattare Google.', 'wp-book-a-call' ),
	};
	return messages[ reason ] ?? sprintf( __( 'Collegamento a Google non riuscito (codice: %s).', 'wp-book-a-call' ), reason || 'sconosciuto' );
};

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

export default function Settings() {
	const [ s, setS ] = useState( null );
	const [ notice, setNotice ] = useState( null );
	const [ saving, setSaving ] = useState( false );
	const [ connecting, setConnecting ] = useState( false );

	useEffect( () => {
		api( '/admin/settings' ).then( setS );

		// Esito del ritorno da Google.
		const params = new URLSearchParams( window.location.search );
		const result = params.get( 'wpbac_google' );
		if ( result ) {
			setNotice(
				'ok' === result
					? { status: 'success', text: __( 'Google Calendar collegato.', 'wp-book-a-call' ) }
					: { status: 'error', text: googleError( params.get( 'wpbac_reason' ) ) }
			);
		}
	}, [] );

	if ( ! s ) {
		return <Spinner />;
	}

	const set = ( key ) => ( value ) => setS( { ...s, [ key ]: value } );

	const run = ( promise, okText ) =>
		promise
			.then( ( data ) => {
				if ( data && undefined !== data.google_connected ) {
					setS( data );
				}
				setNotice( { status: 'success', text: okText } );
			} )
			.catch( ( e ) => setNotice( { status: 'error', text: e.message } ) );

	const save = () => {
		setSaving( true );
		run( api( '/admin/settings', { method: 'POST', data: s } ), __( 'Impostazioni salvate.', 'wp-book-a-call' ) ).finally( () => setSaving( false ) );
	};

	// Salva i campi e manda l'utente a Google per il consenso (l'URL di autorizzazione dipende dai dati salvati).
	const connect = async () => {
		setConnecting( true );
		try {
			const saved = await api( '/admin/settings', { method: 'POST', data: s } );
			if ( ! saved.google_auth_url ) {
				throw new Error( __( 'Inserisci Client ID e Client secret.', 'wp-book-a-call' ) );
			}
			window.location.href = saved.google_auth_url;
		} catch ( e ) {
			setNotice( { status: 'error', text: e.message } );
			setConnecting( false );
		}
	};

	const canConnect = !! s.google_client_id && ( !! s.google_client_secret || s.google_client_secret_set );

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
				<TextControl
					type="email"
					label={ __( 'Email del mittente', 'wp-book-a-call' ) }
					help={ sprintf(
						/* translators: %s: email di amministrazione del sito. */
						__( 'Indirizzo da cui partono le email del plugin. Se vuoto usa l\'email di amministrazione del sito (%s). Per non finire in spam deve appartenere a un dominio abilitato a spedire dal server (SPF/DKIM).', 'wp-book-a-call' ),
						s.admin_email
					) }
					placeholder={ s.admin_email }
					value={ s.from_email }
					onChange={ set( 'from_email' ) }
				/>
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
				<div className="wpbac-admin__grid">
					<TextControl label={ __( 'Nome mostrato come organizzatore', 'wp-book-a-call' ) } value={ s.host_name } onChange={ set( 'host_name' ) } />
					<TextControl type="url" label={ __( 'URL informativa privacy', 'wp-book-a-call' ) } value={ s.privacy_url } onChange={ set( 'privacy_url' ) } />
				</div>
				<Actions>
					<Button
						variant="secondary"
						onClick={ () => run( api( '/admin/settings/test-email', { method: 'POST' } ), __( 'Email di prova inviata.', 'wp-book-a-call' ) ) }
					>
						{ __( 'Invia email di prova', 'wp-book-a-call' ) }
					</Button>
				</Actions>
			</Section>

			<Section title={ __( 'Limiti di prenotazione', 'wp-book-a-call' ) } description={ __( 'Evita che il calendario si riempia e che una persona occupi più slot.', 'wp-book-a-call' ) }>
				<div className="wpbac-admin__grid">
					<TextControl
						type="number"
						min="0"
						label={ __( 'Massimo di call al giorno', 'wp-book-a-call' ) }
						help={ __( 'Vale per tutti i tipi di call insieme. 0 = nessun limite.', 'wp-book-a-call' ) }
						value={ s.max_per_day }
						onChange={ ( v ) => set( 'max_per_day' )( parseInt( v, 10 ) || 0 ) }
					/>
				</div>
				<ToggleControl
					label={ __( 'Una sola call prenotata per cliente', 'wp-book-a-call' ) }
					help={ __( 'Chi ha già una call in programma (stessa email) non può prenotarne un\'altra finché non l\'ha fatta o annullata.', 'wp-book-a-call' ) }
					checked={ s.one_active_per_client }
					onChange={ set( 'one_active_per_client' ) }
				/>
			</Section>

			<Section
				title="Google Calendar"
				description={ __( 'Opzionale. Crea l\'evento nel tuo calendario con link Google Meet ed esclude gli orari già occupati.', 'wp-book-a-call' ) }
			>
				<p className="wpbac-admin__status">
					{ __( 'Stato:', 'wp-book-a-call' ) }{ ' ' }
					<span className={ `wpbac-admin__badge ${ s.google_connected ? 'is-confirmed' : 'is-cancelled' }` }>
						{ s.google_connected ? __( 'Collegato', 'wp-book-a-call' ) : __( 'Non collegato', 'wp-book-a-call' ) }
					</span>
				</p>

				<GoogleGuide redirectUri={ s.google_redirect_uri } />

				<div className="wpbac-admin__grid">
					<TextControl label="Client ID" value={ s.google_client_id } onChange={ set( 'google_client_id' ) } />
					<TextControl
						type="password"
						label="Client secret"
						help={ s.google_client_secret_set ? __( 'Già salvato: compila solo per sostituirlo.', 'wp-book-a-call' ) : '' }
						value={ s.google_client_secret ?? '' }
						onChange={ set( 'google_client_secret' ) }
					/>
					<TextControl
						label={ __( 'ID calendario', 'wp-book-a-call' ) }
						help={ __( '"primary" per il calendario principale.', 'wp-book-a-call' ) }
						value={ s.google_calendar_id }
						onChange={ set( 'google_calendar_id' ) }
					/>
				</div>
				<ToggleControl label={ __( 'Escludi gli orari già occupati nel calendario', 'wp-book-a-call' ) } checked={ s.google_use_busy } onChange={ set( 'google_use_busy' ) } />
				<ToggleControl label={ __( 'Crea un evento con link Google Meet per ogni prenotazione', 'wp-book-a-call' ) } checked={ s.google_use_meet } onChange={ set( 'google_use_meet' ) } />
				<Actions>
					<Button variant="primary" isBusy={ connecting } disabled={ ! canConnect || connecting } onClick={ connect }>
						{ s.google_connected ? __( 'Salva e ricollega con Google', 'wp-book-a-call' ) : __( 'Salva e collega con Google', 'wp-book-a-call' ) }
					</Button>
					{ s.google_connected && (
						<Button
							isDestructive
							variant="tertiary"
							onClick={ () => run( api( '/admin/google/disconnect', { method: 'POST' } ), __( 'Google scollegato.', 'wp-book-a-call' ) ) }
						>
							{ __( 'Scollega', 'wp-book-a-call' ) }
						</Button>
					) }
				</Actions>
				{ ! canConnect && (
					<p className="wpbac-admin__hint">{ __( 'Inserisci Client ID e Client secret per abilitare il collegamento.', 'wp-book-a-call' ) }</p>
				) }
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

			<Section title={ __( 'Dati', 'wp-book-a-call' ) }>
				<ToggleControl
					label={ __( 'Elimina tutti i dati alla disinstallazione del plugin', 'wp-book-a-call' ) }
					help={ __( 'Attenzione: cancella tipi di call, prenotazioni e impostazioni.', 'wp-book-a-call' ) }
					checked={ s.delete_data_on_uninstall }
					onChange={ set( 'delete_data_on_uninstall' ) }
				/>
			</Section>

			<div className="wpbac-admin__actions">
				<Button variant="primary" isBusy={ saving } disabled={ saving } onClick={ save }>
					{ saving ? __( 'Salvataggio…', 'wp-book-a-call' ) : __( 'Salva impostazioni', 'wp-book-a-call' ) }
				</Button>
			</div>
		</div>
	);
}
