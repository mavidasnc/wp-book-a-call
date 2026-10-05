import { useEffect, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, Notice, SelectControl, Spinner } from '@wordpress/components';
import { api, siteTimezone } from '../api';
import BlockCalendar from '../components/BlockCalendar';
import Section from '../components/Section';

/** Date Y-m-d comprese tra due date (limite di sicurezza sugli intervalli molto lunghi). */
const expand = ( from, to ) => {
	const out = [];
	const cursor = new Date( from + 'T00:00:00Z' );
	const end = new Date( to + 'T00:00:00Z' );
	for ( let i = 0; cursor <= end && i < 800; i++ ) {
		out.push( cursor.toISOString().slice( 0, 10 ) );
		cursor.setUTCDate( cursor.getUTCDate() + 1 );
	}
	return out;
};

const label = ( date ) =>
	new Intl.DateTimeFormat( 'it-IT', { day: 'numeric', month: 'short' } ).format( new Date( date + 'T00:00:00' ) );

export default function Exceptions() {
	const [ rows, setRows ] = useState( null );
	const [ types, setTypes ] = useState( [] );
	const [ booked, setBooked ] = useState( new Set() );
	const [ holidays, setHolidays ] = useState( {} );
	const [ scope, setScope ] = useState( 0 ); // 0 = tutti i tipi di call
	const [ error, setError ] = useState( '' );

	useEffect( () => {
		api( '/admin/exceptions' ).then( setRows );
		api( '/admin/event-types' ).then( setTypes );
		api( '/admin/holidays' ).then( ( data ) => setHolidays( data.days ) );

		// Giorni con prenotazioni confermate, mostrati nel calendario con un puntino.
		api( '/admin/bookings?scope=upcoming' ).then( ( list ) =>
			setBooked(
				new Set(
					list.map( ( b ) =>
						new Intl.DateTimeFormat( 'en-CA', { timeZone: siteTimezone } ).format( new Date( b.start_ts * 1000 ) )
					)
				)
			)
		);
	}, [] );

	// Giorni bloccati nell'ambito scelto e, per un tipo specifico, quelli ereditati dal blocco globale.
	const { blocked, inherited, ranges } = useMemo( () => {
		const own = ( rows ?? [] ).filter( ( r ) => ( r.event_type_id ?? 0 ) === scope );
		const global = scope ? ( rows ?? [] ).filter( ( r ) => null === r.event_type_id ) : [];
		return {
			blocked: new Set( own.flatMap( ( r ) => expand( r.date_from, r.date_to ) ) ),
			inherited: new Set( global.flatMap( ( r ) => expand( r.date_from, r.date_to ) ) ),
			ranges: own,
		};
	}, [ rows, scope ] );

	const toggle = ( dates, isBlocked ) =>
		api( '/admin/exceptions/toggle', {
			method: 'POST',
			data: { dates, event_type_id: scope, blocked: isBlocked },
		} )
			.then( ( data ) => {
				setRows( data );
				setError( '' );
			} )
			.catch( ( e ) => setError( e.message ) );

	if ( null === rows ) {
		return <Spinner />;
	}

	return (
		<div className="wpbac-admin__panel">
			<Section
				title={ __( 'Giorni di chiusura', 'wp-book-a-call' ) }
				description={ __( 'Clicca su un giorno per bloccarlo o sbloccarlo: in quei giorni nessuno potrà prenotare. Con Maiusc+clic selezioni un intervallo.', 'wp-book-a-call' ) }
			>
				{ error && (
					<Notice status="error" onRemove={ () => setError( '' ) }>
						{ error }
					</Notice>
				) }
				<div className="wpbac-admin__scope">
					<SelectControl
						label={ __( 'Vale per', 'wp-book-a-call' ) }
						value={ scope }
						options={ [
							{ value: 0, label: __( 'Tutti i tipi di call', 'wp-book-a-call' ) },
							...types.map( ( t ) => ( { value: t.id, label: t.title } ) ),
						] }
						onChange={ ( v ) => setScope( parseInt( v, 10 ) ) }
						__nextHasNoMarginBottom
					/>
				</div>
				<BlockCalendar blocked={ blocked } inherited={ inherited } booked={ booked } holidays={ holidays } onChange={ toggle } />
			</Section>

			<Section title={ __( 'Giorni bloccati', 'wp-book-a-call' ) }>
				{ 0 === ranges.length && (
					<p className="wpbac-admin__empty">{ __( 'Nessun giorno bloccato.', 'wp-book-a-call' ) }</p>
				) }
				<ul className="wpbac-admin__chips">
					{ ranges.map( ( r ) => (
						<li key={ r.id } className="wpbac-admin__chip">
							{ r.date_from === r.date_to ? label( r.date_from ) : `${ label( r.date_from ) } – ${ label( r.date_to ) }` }
							<Button
								variant="link"
								isDestructive
								onClick={ () => toggle( expand( r.date_from, r.date_to ), false ) }
								aria-label={ __( 'Sblocca', 'wp-book-a-call' ) }
							>
								×
							</Button>
						</li>
					) ) }
				</ul>
			</Section>
		</div>
	);
}
