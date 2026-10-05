import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, Notice, SelectControl, Spinner } from '@wordpress/components';
import { api, formatDate } from '../api';

export default function Bookings() {
	const [ scope, setScope ] = useState( 'upcoming' );
	const [ rows, setRows ] = useState( null );
	const [ types, setTypes ] = useState( [] );
	const [ confirmId, setConfirmId ] = useState( 0 );
	const [ error, setError ] = useState( '' );

	const load = () =>
		api( `/admin/bookings?scope=${ scope }` )
			.then( setRows )
			.catch( ( e ) => setError( e.message ) );

	useEffect( () => {
		setRows( null );
		load();
	}, [ scope ] ); // eslint-disable-line react-hooks/exhaustive-deps

	useEffect( () => {
		api( '/admin/event-types' ).then( setTypes );
	}, [] );

	const typeTitle = ( id ) =>
		types.find( ( t ) => t.id === id )?.title ?? `#${ id }`;

	// Doppio click: il primo chiede conferma, il secondo annulla davvero.
	const cancel = ( id ) => {
		if ( confirmId !== id ) {
			setConfirmId( id );
			return;
		}
		api( `/admin/bookings/${ id }/cancel`, { method: 'POST' } )
			.then( () => {
				setConfirmId( 0 );
				load();
			} )
			.catch( ( e ) => setError( e.message ) );
	};

	return (
		<div className="wpbac-admin__panel">
			{ error && (
				<Notice status="error" onRemove={ () => setError( '' ) }>
					{ error }
				</Notice>
			) }
			<SelectControl
				label={ __( 'Mostra', 'wp-book-a-call' ) }
				value={ scope }
				options={ [
					{ value: 'upcoming', label: __( 'Prossime', 'wp-book-a-call' ) },
					{ value: 'past', label: __( 'Passate e annullate', 'wp-book-a-call' ) },
					{ value: 'all', label: __( 'Tutte', 'wp-book-a-call' ) },
				] }
				onChange={ setScope }
			/>
			{ null === rows && <Spinner /> }
			{ rows && 0 === rows.length && (
				<p>{ __( 'Nessuna prenotazione.', 'wp-book-a-call' ) }</p>
			) }
			{ rows && rows.length > 0 && (
				<table className="wpbac-admin__table">
					<thead>
						<tr>
							<th>{ __( 'Quando', 'wp-book-a-call' ) }</th>
							<th>{ __( 'Chi', 'wp-book-a-call' ) }</th>
							<th>{ __( 'Tipo', 'wp-book-a-call' ) }</th>
							<th>{ __( 'Dettagli', 'wp-book-a-call' ) }</th>
							<th />
						</tr>
					</thead>
					<tbody>
						{ rows.map( ( b ) => (
							<tr key={ b.id }>
								<td>
									{ formatDate( b.start_ts ) }
									{ 'cancelled' === b.status && (
										<>
											<br />
											<em>{ __( 'Annullata', 'wp-book-a-call' ) }</em>
										</>
									) }
								</td>
								<td>
									{ b.name }
									<br />
									<a href={ `mailto:${ b.email }` }>{ b.email }</a>
								</td>
								<td>{ typeTitle( b.event_type_id ) }</td>
								<td>
									{ b.meet_url && (
										<a href={ b.meet_url } target="_blank" rel="noreferrer">
											Meet
										</a>
									) }
									{ Object.values( b.answers ).map( ( a, i ) => (
										<div key={ i }>{ a }</div>
									) ) }
								</td>
								<td>
									{ 'confirmed' === b.status && (
										<Button
											variant="secondary"
											isDestructive
											onClick={ () => cancel( b.id ) }
										>
											{ confirmId === b.id
												? __( 'Confermi?', 'wp-book-a-call' )
												: __( 'Annulla', 'wp-book-a-call' ) }
										</Button>
									) }
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }
		</div>
	);
}
