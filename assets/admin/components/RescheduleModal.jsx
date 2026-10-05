import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Button, Modal, Notice } from '@wordpress/components';
import Picker from '../../../blocks/booking/Picker';
import { api, formatDate, siteTimezone } from '../api';

/**
 * Modale per spostare una prenotazione, con lo stesso selettore del sito:
 * due mesi affiancati, orari del giorno scelto e conferma finale.
 * Il plugin aggiorna Google Calendar e avvisa il cliente via email.
 */
export default function RescheduleModal( { booking, onClose, onDone } ) {
	const [ time, setTime ] = useState( 0 );
	const [ notifyClient, setNotifyClient ] = useState( true );
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState( '' );

	// Orari liberi e occupati del periodo mostrato (la prenotazione stessa non occupa il suo vecchio orario).
	const loadSlots = ( from, to ) =>
		api( `/admin/bookings/${ booking.id }/slots?from=${ from }&to=${ to }` ).then( ( data ) => {
			setNotifyClient( false !== data.notify_client );
			return { slots: data.available, taken: data.taken };
		} );

	const submit = () => {
		setSaving( true );
		setError( '' );
		api( `/admin/bookings/${ booking.id }/reschedule`, { method: 'POST', data: { start: time } } )
			.then( ( result ) => onDone( result ) )
			.catch( ( e ) => {
				setError( e.message );
				setSaving( false );
			} );
	};

	return (
		<Modal title={ __( 'Sposta la call', 'wp-book-a-call' ) } onRequestClose={ onClose } className="wpbac-admin-modal is-wide">
			<p className="wpbac-admin-modal__current">
				<strong>{ booking.name }</strong> · { sprintf( __( 'attualmente %s', 'wp-book-a-call' ), formatDate( booking.start_ts ) ) }
			</p>

			{ ! notifyClient && (
				<Notice status="warning" isDismissible={ false }>
					{ __( 'Le email al cliente sono disattivate (scheda Notifiche): lo spostamento avviene ma il cliente non riceve l\'avviso.', 'wp-book-a-call' ) }
				</Notice>
			) }
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			{ /* Stesso markup e stili del widget del sito; il contenitore abilita le regole a due mesi. */ }
			<div className="wp-block-wpbac-booking wpbac-admin-picker">
				<Picker tz={ siteTimezone } loadSlots={ loadSlots } selected={ time } onSelect={ setTime } />
			</div>

			<p className="wpbac-admin-modal__note">{ sprintf( __( 'Gli orari sono nel fuso del sito (%s).', 'wp-book-a-call' ), siteTimezone ) }</p>

			{ time > 0 && (
				<p className="wpbac-admin-modal__summary">
					{ sprintf(
						/* translators: 1: vecchio orario, 2: nuovo orario. */
						__( 'Da %1$s a %2$s', 'wp-book-a-call' ),
						formatDate( booking.start_ts ),
						formatDate( time )
					) }
				</p>
			) }

			<div className="wpbac-admin-modal__actions">
				<Button variant="primary" isBusy={ saving } disabled={ ! time || saving } onClick={ submit }>
					{ notifyClient ? __( 'Conferma e avvisa il cliente', 'wp-book-a-call' ) : __( 'Conferma lo spostamento', 'wp-book-a-call' ) }
				</Button>
				<Button variant="tertiary" onClick={ onClose }>
					{ __( 'Chiudi', 'wp-book-a-call' ) }
				</Button>
			</div>
		</Modal>
	);
}
