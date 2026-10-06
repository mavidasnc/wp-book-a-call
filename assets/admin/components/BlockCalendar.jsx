import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { siteTimezone } from '../api';

const pad = ( n ) => String( n ).padStart( 2, '0' );
const WEEKDAYS = [ 'L', 'M', 'M', 'G', 'V', 'S', 'D' ];
const keyOf = ( y, m, d ) => `${ y }-${ pad( m + 1 ) }-${ pad( d ) }`;

/** Oggi (Y-m-d) nel fuso del sito. */
const todayKey = () =>
	new Intl.DateTimeFormat( 'en-CA', { timeZone: siteTimezone } ).format( new Date() );

/** Tutte le date Y-m-d comprese tra due date (estremi inclusi). */
const between = ( a, b ) => {
	const [ from, to ] = a <= b ? [ a, b ] : [ b, a ];
	const out = [];
	const cursor = new Date( from + 'T00:00:00Z' );
	const end = new Date( to + 'T00:00:00Z' );
	while ( cursor <= end ) {
		out.push( cursor.toISOString().slice( 0, 10 ) );
		cursor.setUTCDate( cursor.getUTCDate() + 1 );
	}
	return out;
};

function Month( { y, m, blocked, inherited, partial, booked, holidays, today, onDay, onPrev, onNext, first } ) {
	const title = new Intl.DateTimeFormat( 'it-IT', { month: 'long', year: 'numeric' } ).format( new Date( y, m, 1 ) );
	const offset = ( new Date( y, m, 1 ).getDay() + 6 ) % 7;
	const count = new Date( y, m + 1, 0 ).getDate();

	return (
		<div className="wpbac-admin__month">
			<div className="wpbac-admin__month-head">
				<button type="button" className="button" onClick={ onPrev } style={ { visibility: first ? 'visible' : 'hidden' } } aria-label={ __( 'Mese precedente', 'wp-book-a-call' ) }>
					‹
				</button>
				<strong>{ title }</strong>
				<button type="button" className="button" onClick={ onNext } style={ { visibility: first ? 'hidden' : 'visible' } } aria-label={ __( 'Mese successivo', 'wp-book-a-call' ) }>
					›
				</button>
			</div>
			<div className="wpbac-admin__cal-grid">
				{ WEEKDAYS.map( ( w, i ) => (
					<span key={ i } className="wpbac-admin__cal-weekday">
						{ w }
					</span>
				) ) }
				{ Array.from( { length: offset } ).map( ( _, i ) => (
					<span key={ 'e' + i } />
				) ) }
				{ Array.from( { length: count } ).map( ( _, i ) => {
					const key = keyOf( y, m, i + 1 );
					const past = key < today;
					const isBlocked = blocked.has( key );
					const holiday = holidays[ key ];
					return (
						<button
							type="button"
							key={ key }
							disabled={ past || inherited.has( key ) || !! holiday }
							aria-pressed={ isBlocked }
							title={
								holiday ??
								( inherited.has( key ) ? __( 'Bloccato per tutti i tipi di call', 'wp-book-a-call' ) : undefined ) ??
								( partial[ key ] ? sprintf( /* translators: %s: elenco dei tipi di call. */ __( 'Bloccato solo per: %s', 'wp-book-a-call' ), partial[ key ].join( ', ' ) ) : undefined )
							}
							className={
								'wpbac-admin__cal-day' +
								( isBlocked ? ' is-blocked' : '' ) +
								( inherited.has( key ) ? ' is-inherited' : '' ) +
								( partial[ key ] && ! isBlocked ? ' is-partial' : '' ) +
								( holiday ? ' is-holiday' : '' ) +
								( booked.has( key ) ? ' has-booking' : '' ) +
								( key === today ? ' is-today' : '' )
							}
							onClick={ ( e ) => onDay( key, e.shiftKey ) }
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
 * Calendario a due mesi per bloccare i giorni. Clic = blocca/sblocca, shift+clic = intervallo.
 * onChange(dates, blocked) riceve le date da modificare e il nuovo stato.
 */
export default function BlockCalendar( { blocked, inherited, partial = {}, booked, holidays = {}, onChange } ) {
	const now = new Date();
	const [ cursor, setCursor ] = useState( { y: now.getFullYear(), m: now.getMonth() } );
	const [ anchor, setAnchor ] = useState( '' );
	const today = todayKey();
	const next = new Date( cursor.y, cursor.m + 1, 1 );

	const move = ( delta ) => {
		const d = new Date( cursor.y, cursor.m + delta, 1 );
		setCursor( { y: d.getFullYear(), m: d.getMonth() } );
	};

	const onDay = ( key, shift ) => {
		// Con shift si applica a tutto l'intervallo dall'ultimo giorno cliccato lo stato opposto a quello di questo giorno.
		const dates = shift && anchor ? between( anchor, key ).filter( ( d ) => d >= today && ! inherited.has( d ) ) : [ key ];
		onChange( dates, ! blocked.has( key ) );
		setAnchor( key );
	};

	const shared = { blocked, inherited, partial, booked, holidays, today, onDay, onPrev: () => move( -1 ), onNext: () => move( 1 ) };

	return (
		<div>
			<div className="wpbac-admin__months">
				<Month y={ cursor.y } m={ cursor.m } first { ...shared } />
				<Month y={ next.getFullYear() } m={ next.getMonth() } { ...shared } />
			</div>
			<p className="wpbac-admin__legend">
				<span className="wpbac-admin__swatch is-blocked" /> { __( 'Bloccato', 'wp-book-a-call' ) }
				{ Object.keys( partial ).length > 0 && (
					<>
						<span className="wpbac-admin__swatch is-partial" /> { __( 'Bloccato solo per altri tipi di call', 'wp-book-a-call' ) }
					</>
				) }
				<span className="wpbac-admin__swatch has-booking" /> { __( 'Ha prenotazioni', 'wp-book-a-call' ) }
				{ Object.keys( holidays ).length > 0 && (
					<>
						<span className="wpbac-admin__swatch is-holiday" /> { __( 'Festività (chiuse in automatico)', 'wp-book-a-call' ) }
					</>
				) }
				<span className="wpbac-admin__legend-hint">{ __( 'Clic per bloccare o sbloccare, Maiusc+clic per un intervallo.', 'wp-book-a-call' ) }</span>
			</p>
		</div>
	);
}
