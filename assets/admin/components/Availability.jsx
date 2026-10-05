import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, TextControl } from '@wordpress/components';
import { DAYS, activeDays, buildMap, firstRanges, formatDays } from '../availability';

/**
 * Disponibilità: fasce orarie comuni + giorni della settimana in cui sono attive.
 * Il backend continua a salvare una mappa per giorno: qui la si costruisce a ogni modifica.
 */
export default function Availability( { value, onChange } ) {
	const [ days, setDays ] = useState( () => activeDays( value ) );
	const [ ranges, setRanges ] = useState( () => firstRanges( value ) );

	const update = ( nextDays, nextRanges ) => {
		setDays( nextDays );
		setRanges( nextRanges );
		onChange( buildMap( nextDays, nextRanges ) );
	};

	const toggleDay = ( key ) =>
		update( days.includes( key ) ? days.filter( ( d ) => d !== key ) : [ ...days, key ], ranges );

	const setRange = ( i, pos, v ) =>
		update(
			days,
			ranges.map( ( r, j ) => ( j === i ? ( 0 === pos ? [ v, r[ 1 ] ] : [ r[ 0 ], v ] ) : r ) )
		);

	return (
		<div className="wpbac-admin__availability">
			<p className="wpbac-admin__label">{ __( 'Giorni in cui sei disponibile', 'wp-book-a-call' ) }</p>
			<div className="wpbac-admin__days" role="group" aria-label={ __( 'Giorni della settimana', 'wp-book-a-call' ) }>
				{ DAYS.map( ( [ key, label ] ) => (
					<Button
						key={ key }
						variant={ days.includes( key ) ? 'primary' : 'secondary' }
						aria-pressed={ days.includes( key ) }
						onClick={ () => toggleDay( key ) }
					>
						{ label }
					</Button>
				) ) }
			</div>

			<p className="wpbac-admin__label">{ __( 'Fasce orarie (valgono per tutti i giorni scelti)', 'wp-book-a-call' ) }</p>
			{ ranges.map( ( range, i ) => (
				<div className="wpbac-admin__range" key={ i }>
					<TextControl
						type="time"
						label={ __( 'Dalle', 'wp-book-a-call' ) }
						value={ range[ 0 ] }
						onChange={ ( v ) => setRange( i, 0, v ) }
						__nextHasNoMarginBottom
					/>
					<TextControl
						type="time"
						label={ __( 'Alle', 'wp-book-a-call' ) }
						value={ range[ 1 ] }
						onChange={ ( v ) => setRange( i, 1, v ) }
						__nextHasNoMarginBottom
					/>
					<Button
						isDestructive
						variant="tertiary"
						onClick={ () => update( days, ranges.filter( ( _, j ) => j !== i ) ) }
					>
						{ __( 'Rimuovi', 'wp-book-a-call' ) }
					</Button>
				</div>
			) ) }
			<Button variant="secondary" onClick={ () => update( days, [ ...ranges, [ '09:00', '12:00' ] ] ) }>
				{ __( 'Aggiungi fascia', 'wp-book-a-call' ) }
			</Button>

			<p className="wpbac-admin__summary">
				{ days.length && ranges.length
					? `${ formatDays( days ) } · ${ ranges.map( ( r ) => `${ r[ 0 ] }-${ r[ 1 ] }` ).join( ', ' ) }`
					: __( 'Con nessun giorno o nessuna fascia il tipo di call non ha orari prenotabili.', 'wp-book-a-call' ) }
			</p>
		</div>
	);
}
