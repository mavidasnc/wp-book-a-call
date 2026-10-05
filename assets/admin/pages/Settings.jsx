import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	Button,
	Notice,
	Spinner,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { api } from '../api';

export default function Settings() {
	const [ s, setS ] = useState( null );
	const [ notice, setNotice ] = useState( null );

	useEffect( () => {
		api( '/admin/settings' ).then( setS );

		// Esito del ritorno da Google.
		const result = new URLSearchParams( window.location.search ).get( 'wpbac_google' );
		if ( result ) {
			setNotice( {
				status: 'ok' === result ? 'success' : 'error',
				text:
					'ok' === result
						? __( 'Google Calendar collegato.', 'wp-book-a-call' )
						: __( 'Collegamento a Google non riuscito.', 'wp-book-a-call' ),
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

	const save = () =>
		run(
			api( '/admin/settings', { method: 'POST', data: s } ),
			__( 'Impostazioni salvate.', 'wp-book-a-call' )
		);

	return (
		<div className="wpbac-admin__panel">
			{ notice && (
				<Notice status={ notice.status } onRemove={ () => setNotice( null ) }>
					{ notice.text }
				</Notice>
			) }

			<h3>{ __( 'Notifiche email', 'wp-book-a-call' ) }</h3>
			<ToggleControl
				label={ __( 'Invia una email a ogni prenotazione', 'wp-book-a-call' ) }
				checked={ s.notify_enabled }
				onChange={ set( 'notify_enabled' ) }
			/>
			<TextControl
				label={ __( 'Destinatari', 'wp-book-a-call' ) }
				help={ __( 'Più indirizzi separati da virgola. L\'invito .ics è allegato.', 'wp-book-a-call' ) }
				value={ s.notify_recipients }
				onChange={ set( 'notify_recipients' ) }
			/>
			<ToggleControl
				label={ __( 'Invia conferma con .ics anche al cliente', 'wp-book-a-call' ) }
				checked={ s.client_email_enabled }
				onChange={ set( 'client_email_enabled' ) }
			/>
			<TextControl
				label={ __( 'Nome mostrato come organizzatore', 'wp-book-a-call' ) }
				value={ s.host_name }
				onChange={ set( 'host_name' ) }
			/>
			<TextControl
				type="url"
				label={ __( 'URL informativa privacy', 'wp-book-a-call' ) }
				value={ s.privacy_url }
				onChange={ set( 'privacy_url' ) }
			/>
			<Button
				variant="secondary"
				onClick={ () =>
					run(
						api( '/admin/settings/test-email', { method: 'POST' } ),
						__( 'Email di prova inviata.', 'wp-book-a-call' )
					)
				}
			>
				{ __( 'Invia email di prova', 'wp-book-a-call' ) }
			</Button>

			<h3>Google Calendar</h3>
			<p>
				{ s.google_connected
					? __( 'Stato: collegato.', 'wp-book-a-call' )
					: __( 'Stato: non collegato (opzionale).', 'wp-book-a-call' ) }
			</p>
			<p>
				{ __( 'URI di reindirizzamento da autorizzare nella Google Cloud Console:', 'wp-book-a-call' ) }{ ' ' }
				<code>{ s.google_redirect_uri }</code>
			</p>
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
			<ToggleControl
				label={ __( 'Escludi gli orari già occupati nel calendario', 'wp-book-a-call' ) }
				checked={ s.google_use_busy }
				onChange={ set( 'google_use_busy' ) }
			/>
			<ToggleControl
				label={ __( 'Crea un evento con link Google Meet per ogni prenotazione', 'wp-book-a-call' ) }
				checked={ s.google_use_meet }
				onChange={ set( 'google_use_meet' ) }
			/>
			<div className="wpbac-admin__actions">
				{ s.google_auth_url && (
					<Button variant="secondary" href={ s.google_auth_url }>
						{ s.google_connected
							? __( 'Ricollega account Google', 'wp-book-a-call' )
							: __( 'Collega account Google', 'wp-book-a-call' ) }
					</Button>
				) }
				{ s.google_connected && (
					<Button
						isDestructive
						variant="tertiary"
						onClick={ () =>
							run(
								api( '/admin/google/disconnect', { method: 'POST' } ),
								__( 'Google scollegato.', 'wp-book-a-call' )
							)
						}
					>
						{ __( 'Scollega', 'wp-book-a-call' ) }
					</Button>
				) }
			</div>
			{ ! s.google_auth_url && (
				<p>
					<em>{ __( 'Salva Client ID e secret per abilitare il collegamento.', 'wp-book-a-call' ) }</em>
				</p>
			) }

			<h3>{ __( 'Dati', 'wp-book-a-call' ) }</h3>
			<ToggleControl
				label={ __( 'Elimina tutti i dati alla disinstallazione del plugin', 'wp-book-a-call' ) }
				checked={ s.delete_data_on_uninstall }
				onChange={ set( 'delete_data_on_uninstall' ) }
			/>

			<div className="wpbac-admin__actions">
				<Button variant="primary" onClick={ save }>
					{ __( 'Salva impostazioni', 'wp-book-a-call' ) }
				</Button>
			</div>
		</div>
	);
}
