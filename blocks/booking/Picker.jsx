import { useEffect, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { call, dayKey, fmtDate, fmtTime } from './utils';

const pad = ( n ) => String( n ).padStart( 2, '0' );
const WEEKDAYS = [ 'L', 'M', 'M', 'G', 'V', 'S', 'D' ];
const keyOf = ( d ) => `${ d.getFullYear() }-${ pad( d.getMonth() + 1 ) }-${ pad( d.getDate() ) }`;

/** Un singolo mese: titolo con frecce, giorni della settimana e griglia dei giorni. */
function Month( { y, m, first, canPrev, byDay, day, onDay, onPrev, onNext } ) {
	const title = new Intl.DateTimeFormat( 'it-IT', { month: 'long', year: 'numeric' } ).format(
		new Date( y, m, 1 )
	);
	// Settimana che parte da lunedì.
	const offset = ( new Date( y, m, 1 ).getDay() + 6 ) % 7;
	const days = new Date( y, m + 1, 0 ).getDate();

	return (
		<div className={ 'wpbac-booking__month' + ( first ? ' is-first' : ' is-second' ) }>
			<div className="wpbac-booking__month-head">
				<button
					type="button"
					className="wpbac-booking__nav is-prev"
					onClick={ onPrev }
					disabled={ ! canPrev }
					aria-label={ __( 'Mese precedente', 'wp-book-a-call' ) }
				>
					‹
				</button>
				<strong aria-live="polite">{ title }</strong>
				<button
					type="button"
					className="wpbac-booking__nav is-next"
					onClick={ onNext }
					aria-label={ __( 'Mese successivo', 'wp-book-a-call' ) }
				>
					›
				</button>
			</div>
			<div className="wpbac-booking__grid">
				{ WEEKDAYS.map( ( w, i ) => (
					<span key={ i } className="wpbac-booking__weekday">
						{ w }
					</span>
				) ) }
				{ Array.from( { length: offset } ).map( ( _, i ) => (
					<span key={ 'e' + i } />
				) ) }
				{ Array.from( { length: days } ).map( ( _, i ) => {
					const key = `${ y }-${ pad( m + 1 ) }-${ pad( i + 1 ) }`;
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
							onClick={ () => onDay( key ) }
						>
							{ i + 1 }
						</button>
					);
				} ) }
			</div>
		</div>
	);
}

/**
 * Due mesi affiancati + elenco degli orari del giorno scelto.
 * Chiama onSelect(timestamp) quando l'utente sceglie uno slot.
 */
export default function Picker( { apiRoot, slug, tz, onSelect } ) {
	const today = new Date();
	const [ cursor, setCursor ] = useState( { y: today.getFullYear(), m: today.getMonth() } );
	const [ slots, setSlots ] = useState( null );
	const [ day, setDay ] = useState( '' );
	const [ error, setError ] = useState( '' );

	// Mese successivo a quello del cursore.
	const next = new Date( cursor.y, cursor.m + 1, 1 );

	// Un solo fetch per i due mesi visibili (con un giorno di margine per i fusi diversi).
	useEffect( () => {
		setSlots( null );
		const from = new Date( cursor.y, cursor.m, 0 ); // ultimo giorno del mese precedente
		const to = new Date( cursor.y, cursor.m + 2, 1 ); // primo giorno del mese dopo il secondo
		call( apiRoot, `/event-types/${ slug }/slots`, {
			params: { from: keyOf( from ), to: keyOf( to ) },
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

	const move = ( delta ) => {
		setCursor( ( c ) => {
			const d = new Date( c.y, c.m + delta, 1 );
			return { y: d.getFullYear(), m: d.getMonth() };
		} );
	};

	const canPrev = ! ( cursor.y === today.getFullYear() && cursor.m === today.getMonth() );
	const monthProps = { byDay, day, onDay: setDay, onPrev: () => move( -1 ), onNext: () => move( 1 ) };
	const hasSlots = Object.keys( byDay ).length > 0;

	return (
		<div className="wpbac-booking__picker">
			<div className="wpbac-booking__calendars">
				{ error && (
					<p className="wpbac-booking__error" role="alert">
						{ error }
					</p>
				) }
				<div className="wpbac-booking__months">
					<Month y={ cursor.y } m={ cursor.m } first canPrev={ canPrev } { ...monthProps } />
					<Month y={ next.getFullYear() } m={ next.getMonth() } canPrev={ false } { ...monthProps } />
				</div>
				{ null === slots && ! error && (
					<p className="wpbac-booking__hint">{ __( 'Caricamento…', 'wp-book-a-call' ) }</p>
				) }
				{ slots && ! hasSlots && (
					<p className="wpbac-booking__hint">
						{ __( 'Nessun orario disponibile in questi mesi.', 'wp-book-a-call' ) }
					</p>
				) }
			</div>

			<div className="wpbac-booking__slots" aria-live="polite">
				{ day && byDay[ day ] && (
					<>
						<strong className="wpbac-booking__slots-title">{ fmtDate( byDay[ day ][ 0 ], tz ) }</strong>
						<div className="wpbac-booking__slot-list">
							{ byDay[ day ].map( ( ts ) => (
								<button
									type="button"
									key={ ts }
									className="wpbac-booking__slot"
									onClick={ () => onSelect( ts ) }
								>
									{ fmtTime( ts, tz ) }
								</button>
							) ) }
						</div>
					</>
				) }
				{ ! day && hasSlots && (
					<p className="wpbac-booking__hint">{ __( 'Scegli un giorno per vedere gli orari.', 'wp-book-a-call' ) }</p>
				) }
			</div>
		</div>
	);
}
