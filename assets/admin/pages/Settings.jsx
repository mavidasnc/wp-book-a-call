import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, Notice, Spinner, TextControl, ToggleControl } from '@wordpress/components';
import { api } from '../api';
import Actions from '../components/Actions';
import Section from '../components/Section';

export default function Settings() {
	const [ s, setS ] = useState( null );
	const [ notice, setNotice ] = useState( null );
	const [ saving, setSaving ] = useState( false );

	useEffect( () => {
		api( '/admin/settings' ).then( setS );

		// Esito del ritorno da Google.
		const result = new URLSearchParams( window.location.search ).get( 'wpbac_google' );
		if ( result ) {
			setNotice( {
				status: 'ok' === result ? 'success' : 'error',
				text: 'ok' === result ? __( 'Google Calendar collegato.', 'wp-book-a-call' ) : __( 'Collegamento a Google non riuscito.', 'wp-book-a-call' ),
			} );
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
				<ToggleControl label={ __( 'Invia la conferma con .ics anche al cliente', 'wp-book-a-call' ) } checked={ s.client_email_enabled } onChange={ set( 'client_email_enabled' ) } />
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

			<Section
				title="Google Calendar"
				description={ s.google_connected ? __( 'Collegato: le prenotazioni creano un evento nel calendario.', 'wp-book-a-call' ) : __( 'Opzionale. Crea l\'evento con link Meet ed esclude gli orari già occupati.', 'wp-book-a-call' ) }
			>
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
				<p className="wpbac-admin__hint">
					{ __( 'URI di reindirizzamento da autorizzare nella Google Cloud Console:', 'wp-book-a-call' ) } <code>{ s.google_redirect_uri }</code>
				</p>
				<Actions>
					{ s.google_auth_url && (
						<Button variant="secondary" href={ s.google_auth_url }>
							{ s.google_connected ? __( 'Ricollega account Google', 'wp-book-a-call' ) : __( 'Collega account Google', 'wp-book-a-call' ) }
						</Button>
					) }
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
				{ ! s.google_auth_url && (
					<p className="wpbac-admin__hint">{ __( 'Salva Client ID e secret per abilitare il collegamento.', 'wp-book-a-call' ) }</p>
				) }
			</Section>

			<Section
				title="Webhook"
				description={ __( 'A ogni prenotazione creata, spostata o annullata il sito invia un POST JSON all\'indirizzo indicato (ad esempio un workflow n8n).', 'wp-book-a-call' ) }
			>
				<TextControl type="url" label={ __( 'URL del webhook', 'wp-book-a-call' ) } value={ s.webhook_url } onChange={ set( 'webhook_url' ) } />
				<TextControl
					type="password"
					label={ __( 'Segreto per la firma', 'wp-book-a-call' ) }
					help={ s.webhook_secret_set ? __( 'Già salvato: compila solo per sostituirlo.', 'wp-book-a-call' ) : __( 'Facoltativo. Se presente, ogni richiesta ha l\'header X-Wpbac-Signature (HMAC SHA-256 del corpo).', 'wp-book-a-call' ) }
					value={ s.webhook_secret ?? '' }
					onChange={ set( 'webhook_secret' ) }
				/>
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
