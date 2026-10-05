import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	Button,
	Notice,
	SelectControl,
	Spinner,
	TextControl,
} from '@wordpress/components';
import { api } from '../api';

const EMPTY = { date_from: '', date_to: '', event_type_id: 0, note: '' };

export default function Exceptions() {
	const [ rows, setRows ] = useState( null );
	const [ types, setTypes ] = useState( [] );
	const [ form, setForm ] = useState( EMPTY );
	const [ error, setError ] = useState( '' );

	const load = () => api( '/admin/exceptions' ).then( setRows );

	useEffect( () => {
		load();
		api( '/admin/event-types' ).then( setTypes );
	}, [] );

	const set = ( key ) => ( value ) => setForm( { ...form, [ key ]: value } );

	const add = () =>
		api( '/admin/exceptions', {
			method: 'POST',
			data: { ...form, date_to: form.date_to || form.date_from },
		} )
			.then( () => {
				setForm( EMPTY );
				setError( '' );
				load();
			} )
			.catch( ( e ) => setError( e.message ) );

	const remove = ( id ) =>
		api( `/admin/exceptions/${ id }`, { method: 'DELETE' } ).then( load );

	const typeTitle = ( id ) =>
		id
			? types.find( ( t ) => t.id === id )?.title
			: __( 'Tutti', 'wp-book-a-call' );

	return (
		<div className="wpbac-admin__panel">
			<p>
				{ __(
					'Giorni in cui non accetti prenotazioni (ferie, festività). Per un solo giorno lascia vuota la data finale.',
					'wp-book-a-call'
				) }
			</p>
			{ error && <Notice status="error">{ error }</Notice> }
			<div className="wpbac-admin__row">
				<TextControl
					type="date"
					label={ __( 'Dal', 'wp-book-a-call' ) }
					value={ form.date_from }
					onChange={ set( 'date_from' ) }
				/>
				<TextControl
					type="date"
					label={ __( 'Al', 'wp-book-a-call' ) }
					value={ form.date_to }
					onChange={ set( 'date_to' ) }
				/>
				<SelectControl
					label={ __( 'Vale per', 'wp-book-a-call' ) }
					value={ form.event_type_id }
					options={ [
						{ value: 0, label: __( 'Tutti i tipi di call', 'wp-book-a-call' ) },
						...types.map( ( t ) => ( { value: t.id, label: t.title } ) ),
					] }
					onChange={ ( v ) => set( 'event_type_id' )( parseInt( v, 10 ) ) }
				/>
				<TextControl
					label={ __( 'Nota', 'wp-book-a-call' ) }
					value={ form.note }
					onChange={ set( 'note' ) }
				/>
			</div>
			<div className="wpbac-admin__actions">
				<Button variant="primary" onClick={ add } disabled={ ! form.date_from }>
					{ __( 'Aggiungi', 'wp-book-a-call' ) }
				</Button>
			</div>
			{ null === rows && <Spinner /> }
			{ rows && rows.length > 0 && (
				<table className="wpbac-admin__table" style={ { marginTop: 24 } }>
					<tbody>
						{ rows.map( ( r ) => (
							<tr key={ r.id }>
								<td>
									{ r.date_from === r.date_to
										? r.date_from
										: `${ r.date_from } → ${ r.date_to }` }
								</td>
								<td>{ typeTitle( r.event_type_id ) }</td>
								<td>{ r.note }</td>
								<td>
									<Button isDestructive variant="link" onClick={ () => remove( r.id ) }>
										{ __( 'Elimina', 'wp-book-a-call' ) }
									</Button>
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }
		</div>
	);
}
