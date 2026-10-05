import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { call, dayKey, fmtDate, fmtTime } from './utils';

const pad = ( n ) => String( n ).padStart( 2, '0' );
const WEEKDAYS = [ 'L', 'M', 'M', 'G', 'V', 'S', 'D' ];
const keyOf = ( d ) => `${ d.getFullYear() }-${ pad( d.getMonth() + 1 ) }-${ pad( d.getDate() ) }`;

/** Lucchetto: l'orario è già occupato. */
function LockIcon() {
	return (
		<svg className="wpbac-booking__lock" width="12" height="12" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
			<path fill="currentColor" d="M17 9V7a5 5 0 0 0-10 0v2H5v12h14V9h-2zM9 7a3 3 0 0 1 6 0v2H9V7zm4 9.7V18h-2v-1.3a2 2 0 1 1 2 0z" />
		</svg>
	);
}

/** Un singolo mese: titolo con frecce, giorni della settimana e griglia dei giorni. */
function Month( { y, m, first, canPrev, byDay, takenByDay, day, onDay, onPrev, onNext } ) {
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
					// Giorno con orari previsti ma tutti occupati: resta cliccabile per mostrarli.
					const full = ! available && !! takenByDay[ key ];
					return (
						<button
							type="button"
							key={ key }
							className={
								'wpbac-booking__day' +
								( available ? ' is-available' : '' ) +
								( full ? ' is-full' : '' ) +
								( key === day ? ' is-selected' : '' )
							}
							disabled={ ! available && ! full }
							title={ full ? __( 'Tutto occupato', 'wp-book-a-call' ) : undefined }
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
 * Di base legge gli orari dall'API pubblica; l'admin passa `loadSlots( from, to )` (date Y-m-d),
 * che restituisce `{ slots, taken }`, e `selected` per evidenziare l'orario scelto.
 */
export default function Picker( { apiRoot, slug, tz, onSelect, loadSlots, selected } ) {
	// Il caricatore può cambiare a ogni render: lo si legge da un ref per non rifare il fetch.
	const loader = useRef( loadSlots );
	loader.current = loadSlots;
	const today = new Date();
	const [ cursor, setCursor ] = useState( { y: today.getFullYear(), m: today.getMonth() } );
	const [ slots, setSlots ] = useState( null );
	const [ taken, setTaken ] = useState( [] );
	const [ day, setDay ] = useState( '' );
	const [ error, setError ] = useState( '' );

	// Mese successivo a quello del cursore.
	const next = new Date( cursor.y, cursor.m + 1, 1 );

	// Un solo fetch per i due mesi visibili (con un giorno di margine per i fusi diversi).
	useEffect( () => {
		setSlots( null );
		const from = new Date( cursor.y, cursor.m, 0 ); // ultimo giorno del mese precedente
		const to = new Date( cursor.y, cursor.m + 2, 1 ); // primo giorno del mese dopo il secondo
		const request = loader.current
			? loader.current( keyOf( from ), keyOf( to ) )
			: call( apiRoot, `/event-types/${ slug }/slots`, {
					params: { from: keyOf( from ), to: keyOf( to ) },
			  } );
		request
			.then( ( data ) => {
				// Orari occupati (previsti ma già impegnati): il widget li mostra non selezionabili.
				setTaken( data.taken ?? [] );
				setSlots( data.slots );
			} )
			.catch( ( e ) => setError( e.message ) );
	}, [ apiRoot, slug, cursor ] );

	// Slot raggruppati per giorno nel fuso scelto dall'utente.
	const group = ( list ) => {
		const map = {};
		list.forEach( ( ts ) => {
			( map[ dayKey( ts, tz ) ] ??= [] ).push( ts );
		} );
		return map;
	};
	const byDay = useMemo( () => group( slots ?? [] ), [ slots, tz ] ); // eslint-disable-line react-hooks/exhaustive-deps
	const takenByDay = useMemo( () => group( taken ), [ taken, tz ] ); // eslint-disable-line react-hooks/exhaustive-deps

	// Orari del giorno scelto, liberi e occupati in ordine di ora.
	const dayItems = day
		? [
				...( byDay[ day ] ?? [] ).map( ( ts ) => ( { ts, taken: false } ) ),
				...( takenByDay[ day ] ?? [] ).map( ( ts ) => ( { ts, taken: true } ) ),
		  ].sort( ( a, b ) => a.ts - b.ts )
		: [];

	const move = ( delta ) => {
		setCursor( ( c ) => {
			const d = new Date( c.y, c.m + delta, 1 );
			return { y: d.getFullYear(), m: d.getMonth() };
		} );
	};

	const canPrev = ! ( cursor.y === today.getFullYear() && cursor.m === today.getMonth() );
	const monthProps = { byDay, takenByDay, day, onDay: setDay, onPrev: () => move( -1 ), onNext: () => move( 1 ) };
	const hasSlots = Object.keys( byDay ).length > 0 || Object.keys( takenByDay ).length > 0;

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
				{ dayItems.length > 0 && (
					<>
						<strong className="wpbac-booking__slots-title">{ fmtDate( dayItems[ 0 ].ts, tz ) }</strong>
						<div className="wpbac-booking__slot-list">
							{ dayItems.map( ( item ) =>
								item.taken ? (
									<button
										type="button"
										key={ item.ts }
										className="wpbac-booking__slot is-taken"
										disabled
										title={ __( 'Già occupato', 'wp-book-a-call' ) }
									>
										<LockIcon />
										{ fmtTime( item.ts, tz ) }
										<span className="wpbac-booking__sr">{ __( 'occupato', 'wp-book-a-call' ) }</span>
									</button>
								) : (
									<button
										type="button"
										key={ item.ts }
										className={ 'wpbac-booking__slot' + ( item.ts === selected ? ' is-selected' : '' ) }
										aria-pressed={ item.ts === selected }
										onClick={ () => onSelect( item.ts ) }
									>
										{ fmtTime( item.ts, tz ) }
									</button>
								)
							) }
						</div>
						{ dayItems.some( ( item ) => item.taken ) && (
							<p className="wpbac-booking__legend">
								<LockIcon /> { __( 'Gli orari con il lucchetto sono già occupati.', 'wp-book-a-call' ) }
							</p>
						) }
					</>
				) }
				{ ! day && hasSlots && (
					<p className="wpbac-booking__hint">{ __( 'Scegli un giorno per vedere gli orari.', 'wp-book-a-call' ) }</p>
				) }
			</div>
		</div>
	);
}
