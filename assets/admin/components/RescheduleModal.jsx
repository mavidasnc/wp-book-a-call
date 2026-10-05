import { useEffect, useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Button, Modal, Notice, SelectControl, Spinner } from '@wordpress/components';
import { api, formatDate, siteTimezone } from '../api';

/** Data Y-m-d di un timestamp nel fuso del sito. */
const dayOf = ( ts ) => new Intl.DateTimeFormat( 'en-CA', { timeZone: siteTimezone } ).format( new Date( ts * 1000 ) );

const dayLabel = ( ts ) =>
	new Intl.DateTimeFormat( 'it-IT', {
		timeZone: siteTimezone,
		weekday: 'long',
		day: 'numeric',
		month: 'long',
		year: 'numeric',
	} ).format( new Date( ts * 1000 ) );

const timeLabel = ( ts ) =>
	new Intl.DateTimeFormat( 'it-IT', { timeZone: siteTimezone, hour: '2-digit', minute: '2-digit' } ).format( new Date( ts * 1000 ) );

/**
 * Modale per spostare una prenotazione: l'amministratore sceglie giorno e orario tra quelli liberi,
 * il plugin aggiorna Google Calendar e avvisa il cliente via email.
 */
export default function RescheduleModal( { booking, onClose, onDone } ) {
	const [ data, setData ] = useState( null );
	const [ day, setDay ] = useState( '' );
	const [ time, setTime ] = useState( '' );
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState( '' );

	// Orari liberi dei prossimi due mesi (la prenotazione stessa non occupa il suo vecchio orario).
	useEffect( () => {
		const from = dayOf( Date.now() / 1000 );
		const to = dayOf( Date.now() / 1000 + 62 * 86400 );
		api( `/admin/bookings/${ booking.id }/slots?from=${ from }&to=${ to }` )
			.then( setData )
			.catch( ( e ) => setError( e.message ) );
	}, [ booking.id ] );

	// Orari liberi raggruppati per giorno.
	const byDay = useMemo( () => {
		const map = {};
		( data?.available ?? [] ).forEach( ( ts ) => {
			( map[ dayOf( ts ) ] ??= [] ).push( ts );
		} );
		return map;
	}, [ data ] );

	const days = Object.keys( byDay ).sort();

	const submit = () => {
		setSaving( true );
		setError( '' );
		api( `/admin/bookings/${ booking.id }/reschedule`, { method: 'POST', data: { start: parseInt( time, 10 ) } } )
			.then( ( result ) => onDone( result ) )
			.catch( ( e ) => {
				setError( e.message );
				setSaving( false );
			} );
	};

	return (
		<Modal title={ __( 'Sposta la call', 'wp-book-a-call' ) } onRequestClose={ onClose } className="wpbac-admin-modal">
			<p className="wpbac-admin-modal__current">
				<strong>{ booking.name }</strong> · { sprintf( __( 'attualmente %s', 'wp-book-a-call' ), formatDate( booking.start_ts ) ) }
			</p>

			{ ! data && ! error && <Spinner /> }
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			{ data && (
				<>
					{ ! data.notify_client && (
						<Notice status="warning" isDismissible={ false }>
							{ __( 'Le email al cliente sono disattivate (scheda Notifiche): lo spostamento avviene ma il cliente non riceve l\'avviso.', 'wp-book-a-call' ) }
						</Notice>
					) }
					{ 0 === days.length ? (
						<p className="wpbac-admin__empty">{ __( 'Nessun orario libero nei prossimi due mesi.', 'wp-book-a-call' ) }</p>
					) : (
						<>
							<SelectControl
								label={ __( 'Nuovo giorno', 'wp-book-a-call' ) }
								value={ day }
								options={ [
									{ value: '', label: __( 'Scegli un giorno', 'wp-book-a-call' ) },
									...days.map( ( d ) => ( { value: d, label: dayLabel( byDay[ d ][ 0 ] ) } ) ),
								] }
								onChange={ ( v ) => {
									setDay( v );
									setTime( '' );
								} }
								__nextHasNoMarginBottom
							/>
							<SelectControl
								label={ sprintf( __( 'Nuovo orario (%s)', 'wp-book-a-call' ), siteTimezone ) }
								value={ time }
								disabled={ ! day }
								options={ [
									{ value: '', label: __( 'Scegli un orario', 'wp-book-a-call' ) },
									...( byDay[ day ] ?? [] ).map( ( ts ) => ( { value: String( ts ), label: timeLabel( ts ) } ) ),
								] }
								onChange={ setTime }
								__nextHasNoMarginBottom
							/>
						</>
					) }
				</>
			) }

			<div className="wpbac-admin-modal__actions">
				<Button variant="primary" isBusy={ saving } disabled={ ! time || saving } onClick={ submit }>
					{ data?.notify_client === false ? __( 'Sposta', 'wp-book-a-call' ) : __( 'Sposta e avvisa il cliente', 'wp-book-a-call' ) }
				</Button>
				<Button variant="tertiary" onClick={ onClose }>
					{ __( 'Chiudi', 'wp-book-a-call' ) }
				</Button>
			</div>
		</Modal>
	);
}
