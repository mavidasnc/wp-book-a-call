import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Button, Notice, Spinner, TextControl, TextareaControl, ToggleControl } from '@wordpress/components';
import { api } from '../api';
import Actions from '../components/Actions';
import GoogleGuide from '../components/GoogleGuide';
import Section from '../components/Section';
import useSettings from '../hooks/useSettings';

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

/** Scheda Impostazioni: limiti e chiusure, Google Calendar, privacy e dati. */
export default function Settings() {
	const { s, set, notice, setNotice, saving, save, run } = useSettings();
	const [ connecting, setConnecting ] = useState( false );

	// Esito del ritorno da Google.
	useEffect( () => {
		const params = new URLSearchParams( window.location.search );
		const result = params.get( 'wpbac_google' );
		if ( result ) {
			setNotice(
				'ok' === result
					? { status: 'success', text: __( 'Google Calendar collegato.', 'wp-book-a-call' ) }
					: { status: 'error', text: googleError( params.get( 'wpbac_reason' ) ) }
			);
		}
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

	if ( ! s ) {
		return <Spinner />;
	}

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

			<Section title={ __( 'Limiti e chiusure', 'wp-book-a-call' ) } description={ __( 'Evita che il calendario si riempia, che una persona occupi più slot e che si prenoti nei giorni di festa.', 'wp-book-a-call' ) }>
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
				<ToggleControl
					label={ __( 'Non accettare prenotazioni per il primo giorno operativo successivo a oggi', 'wp-book-a-call' ) }
					help={ __( 'Weekend e festività non contano: se oggi è venerdì e il lunedì è lavorativo, il lunedì non è prenotabile; se il lunedì è festivo, non lo è il martedì. Oggi resta regolato dal preavviso minimo del tipo di call.', 'wp-book-a-call' ) }
					checked={ s.skip_next_day }
					onChange={ set( 'skip_next_day' ) }
				/>
				<ToggleControl
					label={ __( 'Chiudi automaticamente nelle festività italiane', 'wp-book-a-call' ) }
					help={ __( 'Capodanno, Epifania, Pasqua e Lunedì dell\'Angelo, 25 aprile, 1 maggio, 2 giugno, Ferragosto, Ognissanti, Immacolata, Natale e Santo Stefano. Altri giorni (ferie, patrono locale) si bloccano dalla scheda Eccezioni.', 'wp-book-a-call' ) }
					checked={ s.close_holidays }
					onChange={ set( 'close_holidays' ) }
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
				<TextControl
					type="number"
					min="0"
					label={ __( 'Promemoria sul tuo calendario (minuti prima)', 'wp-book-a-call' ) }
					help={ __( 'Vale solo per te, non per il cliente (che riceve i promemoria via email). 0 = promemoria predefiniti del calendario. Si applica ai nuovi eventi.', 'wp-book-a-call' ) }
					value={ s.google_reminder_minutes }
					onChange={ ( v ) => set( 'google_reminder_minutes' )( parseInt( v, 10 ) || 0 ) }
				/>
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

			<Section title={ __( 'Privacy e dati', 'wp-book-a-call' ) }>
				<TextControl
					type="url"
					label={ __( 'URL informativa privacy', 'wp-book-a-call' ) }
					help={ __( 'Collegata alla casella di consenso nel modulo di prenotazione.', 'wp-book-a-call' ) }
					value={ s.privacy_url }
					onChange={ set( 'privacy_url' ) }
				/>
				<TextareaControl
					rows={ 9 }
					label={ __( 'Testo dell\'informativa (usato se manca l\'URL)', 'wp-book-a-call' ) }
					help={ __( 'Appare sotto la casella di consenso solo quando l\'URL qui sopra è vuoto. Segnaposto: {host} organizzatore, {site} nome del sito, {admin_email} email di contatto. È un modello generico: fallo rivedere prima di usarlo.', 'wp-book-a-call' ) }
					value={ s.privacy_text }
					onChange={ set( 'privacy_text' ) }
				/>
				<Actions>
					<Button variant="secondary" onClick={ () => set( 'privacy_text' )( s.default_privacy ) }>
						{ __( 'Ripristina il testo standard', 'wp-book-a-call' ) }
					</Button>
				</Actions>
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
