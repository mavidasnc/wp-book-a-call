import { useEffect, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { call, dayKey, fmtDate, fmtTime } from './utils';

const pad = ( n ) => String( n ).padStart( 2, '0' );
const WEEKDAYS = [ 'L', 'M', 'M', 'G', 'V', 'S', 'D' ];

/**
 * Calendario mensile + elenco degli orari del giorno scelto.
 * Chiama onSelect(timestamp) quando l'utente sceglie uno slot.
 */
export default function Picker( { apiRoot, slug, tz, onSelect } ) {
	const today = new Date();
	const [ cursor, setCursor ] = useState( {
		y: today.getFullYear(),
		m: today.getMonth(),
	} );
	const [ slots, setSlots ] = useState( null );
	const [ day, setDay ] = useState( '' );
	const [ error, setError ] = useState( '' );

	// Carica gli slot del mese visibile (intervallo allargato di un giorno per i fusi diversi).
	useEffect( () => {
		setSlots( null );
		const first = new Date( cursor.y, cursor.m, 1 );
		const last = new Date( cursor.y, cursor.m + 1, 0 );
		const fmt = ( d ) => `${ d.getFullYear() }-${ pad( d.getMonth() + 1 ) }-${ pad( d.getDate() ) }`;
		const from = new Date( first.getTime() - 86400000 );
		const to = new Date( last.getTime() + 86400000 );

		call( apiRoot, `/event-types/${ slug }/slots`, {
			params: { from: fmt( from ), to: fmt( to ) },
		} )
			.then( ( data ) => setSlots( data.slots ) )
			.catch( ( e ) => setError( e.message ) );
	}, [ apiRoot, slug, cursor ] );

	// Slot raggruppati per giorno nel fuso scelto dall'utente.
	const byDay = useMemo( () => {
		const map = {};
		( slots ?? [] ).forEach( ( ts ) => {
			( map[ dayKey( ts, tz ) ] ??= [] ).push( ts );
		} );
		return map;
	}, [ slots, tz ] );

	const monthLabel = new Intl.DateTimeFormat( 'it-IT', {
		month: 'long',
		year: 'numeric',
	} ).format( new Date( cursor.y, cursor.m, 1 ) );

	// Celle del mese, con settimana che parte da lunedì.
	const offset = ( new Date( cursor.y, cursor.m, 1 ).getDay() + 6 ) % 7;
	const daysInMonth = new Date( cursor.y, cursor.m + 1, 0 ).getDate();
	const isCurrentMonth = cursor.y === today.getFullYear() && cursor.m === today.getMonth();

	const move = ( delta ) => {
		setDay( '' );
		setCursor( ( c ) => {
			const d = new Date( c.y, c.m + delta, 1 );
			return { y: d.getFullYear(), m: d.getMonth() };
		} );
	};

	return (
		<div className="wpbac-booking__picker">
			{ error && <p className="wpbac-booking__error" role="alert">{ error }</p> }

			<div className="wpbac-booking__calendar">
				<div className="wpbac-booking__month">
					<button type="button" onClick={ () => move( -1 ) } disabled={ isCurrentMonth } aria-label={ __( 'Mese precedente', 'wp-book-a-call' ) }>
						‹
					</button>
					<strong aria-live="polite">{ monthLabel }</strong>
					<button type="button" onClick={ () => move( 1 ) } aria-label={ __( 'Mese successivo', 'wp-book-a-call' ) }>
						›
					</button>
				</div>
				<div className="wpbac-booking__grid" role="grid">
					{ WEEKDAYS.map( ( w, i ) => (
						<span key={ i } className="wpbac-booking__weekday">{ w }</span>
					) ) }
					{ Array.from( { length: offset } ).map( ( _, i ) => (
						<span key={ 'e' + i } />
					) ) }
					{ Array.from( { length: daysInMonth } ).map( ( _, i ) => {
						const key = `${ cursor.y }-${ pad( cursor.m + 1 ) }-${ pad( i + 1 ) }`;
						const available = !! byDay[ key ];
						return (
							<button
								type="button"
								key={ key }
								className={
									'wpbac-booking__day' +
									( available ? ' is-available' : '' ) +
									( key === day ? ' is-selected' : '' )
								}
								disabled={ ! available }
								aria-pressed={ key === day }
								onClick={ () => setDay( key ) }
							>
								{ i + 1 }
							</button>
						);
					} ) }
				</div>
				{ null === slots && ! error && (
					<p className="wpbac-booking__hint">{ __( 'Caricamento…', 'wp-book-a-call' ) }</p>
				) }
				{ slots && 0 === Object.keys( byDay ).length && (
					<p className="wpbac-booking__hint">
						{ __( 'Nessun orario disponibile in questo mese.', 'wp-book-a-call' ) }
					</p>
				) }
			</div>

			<div className="wpbac-booking__slots" aria-live="polite">
				{ day && byDay[ day ] && (
					<>
						<strong>{ fmtDate( byDay[ day ][ 0 ], tz ) }</strong>
						<div className="wpbac-booking__slot-list">
							{ byDay[ day ].map( ( ts ) => (
								<button type="button" key={ ts } className="wpbac-booking__slot" onClick={ () => onSelect( ts ) }>
									{ fmtTime( ts, tz ) }
								</button>
							) ) }
						</div>
					</>
				) }
				{ ! day && Object.keys( byDay ).length > 0 && (
					<p className="wpbac-booking__hint">{ __( 'Scegli un giorno per vedere gli orari.', 'wp-book-a-call' ) }</p>
				) }
			</div>
		</div>
	);
}
