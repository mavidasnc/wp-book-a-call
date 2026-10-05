import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, Notice, SelectControl, Spinner } from '@wordpress/components';
import { api, downloadFile, formatDate } from '../api';
import Section from '../components/Section';

export default function Bookings() {
	const [ scope, setScope ] = useState( 'upcoming' );
	const [ rows, setRows ] = useState( null );
	const [ types, setTypes ] = useState( [] );
	const [ confirmId, setConfirmId ] = useState( 0 );
	const [ error, setError ] = useState( '' );
	const [ exporting, setExporting ] = useState( false );

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

	const typeTitle = ( id ) => types.find( ( t ) => t.id === id )?.title ?? `#${ id }`;

	// Esporta in CSV le prenotazioni del filtro corrente.
	const exportCsv = () => {
		setExporting( true );
		api( `/admin/bookings/export?scope=${ scope }` )
			.then( ( data ) => downloadFile( data.filename, data.csv ) )
			.catch( ( e ) => setError( e.message ) )
			.finally( () => setExporting( false ) );
	};

	// Doppio clic: il primo chiede conferma, il secondo annulla davvero.
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
			<Section
				title={ __( 'Prenotazioni', 'wp-book-a-call' ) }
				description={ __( 'Le call prenotate dal sito. Annullando una prenotazione parte una email al cliente e l\'evento viene tolto da Google Calendar.', 'wp-book-a-call' ) }
			>
				{ error && (
					<Notice status="error" onRemove={ () => setError( '' ) }>
						{ error }
					</Notice>
				) }
				<div className="wpbac-admin__toolbar">
					<SelectControl
						label={ __( 'Mostra', 'wp-book-a-call' ) }
						value={ scope }
						options={ [
							{ value: 'upcoming', label: __( 'Prossime', 'wp-book-a-call' ) },
							{ value: 'past', label: __( 'Passate e annullate', 'wp-book-a-call' ) },
							{ value: 'all', label: __( 'Tutte', 'wp-book-a-call' ) },
						] }
						onChange={ setScope }
						__nextHasNoMarginBottom
					/>
					<Button variant="secondary" isBusy={ exporting } disabled={ exporting } onClick={ exportCsv }>
						{ __( 'Esporta CSV', 'wp-book-a-call' ) }
					</Button>
				</div>

				{ null === rows && <Spinner /> }
				{ rows && 0 === rows.length && (
					<p className="wpbac-admin__empty">{ __( 'Nessuna prenotazione da mostrare.', 'wp-book-a-call' ) }</p>
				) }
				{ rows && rows.length > 0 && (
					<table className="wp-list-table widefat striped wpbac-admin__table">
						<thead>
							<tr>
								<th>{ __( 'Quando', 'wp-book-a-call' ) }</th>
								<th>{ __( 'Chi', 'wp-book-a-call' ) }</th>
								<th>{ __( 'Dettagli', 'wp-book-a-call' ) }</th>
								<th />
							</tr>
						</thead>
						<tbody>
							{ rows.map( ( b ) => (
								<tr key={ b.id }>
									<td>
										<strong>{ formatDate( b.start_ts ) }</strong>
										<br />
										<span className={ `wpbac-admin__badge is-${ b.status }` }>
											{ 'confirmed' === b.status ? __( 'Confermata', 'wp-book-a-call' ) : __( 'Annullata', 'wp-book-a-call' ) }
										</span>
									</td>
									<td>
										{ b.name }
										<br />
										<a href={ `mailto:${ b.email }` }>{ b.email }</a>
									</td>
									<td>
										<em>{ typeTitle( b.event_type_id ) }</em>
										{ b.meet_url && (
											<>
												{ ' · ' }
												<a href={ b.meet_url } target="_blank" rel="noreferrer">
													Meet
												</a>
											</>
										) }
										{ Object.values( b.answers ).map( ( a, i ) => (
											<div key={ i }>{ a }</div>
										) ) }
									</td>
									<td>
										{ 'confirmed' === b.status && (
											<Button variant="secondary" isDestructive onClick={ () => cancel( b.id ) }>
												{ confirmId === b.id ? __( 'Confermi?', 'wp-book-a-call' ) : __( 'Annulla', 'wp-book-a-call' ) }
											</Button>
										) }
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
				) }
			</Section>
		</div>
	);
}
