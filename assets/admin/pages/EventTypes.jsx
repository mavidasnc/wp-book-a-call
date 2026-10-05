import { Fragment, useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	Button,
	Notice,
	SelectControl,
	Spinner,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';
import { api } from '../api';

const DAYS = [
	[ 'mon', __( 'Lun', 'wp-book-a-call' ) ],
	[ 'tue', __( 'Mar', 'wp-book-a-call' ) ],
	[ 'wed', __( 'Mer', 'wp-book-a-call' ) ],
	[ 'thu', __( 'Gio', 'wp-book-a-call' ) ],
	[ 'fri', __( 'Ven', 'wp-book-a-call' ) ],
	[ 'sat', __( 'Sab', 'wp-book-a-call' ) ],
	[ 'sun', __( 'Dom', 'wp-book-a-call' ) ],
];

const NEW_TYPE = {
	title: '',
	slug: '',
	description: '',
	duration_min: 30,
	slot_step_min: 30,
	buffer_before_min: 0,
	buffer_after_min: 0,
	min_notice_hours: 12,
	max_days_ahead: 60,
	location_type: 'meet',
	location_value: '',
	weekly_hours: {},
	questions: [],
	active: true,
};

/** Editor delle fasce orarie settimanali. */
function WeeklyHours( { value, onChange } ) {
	const setDay = ( day, ranges ) => onChange( { ...value, [ day ]: ranges } );

	return (
		<div className="wpbac-admin__hours">
			{ DAYS.map( ( [ key, label ] ) => {
				const ranges = value[ key ] ?? [];
				return (
					<Fragment key={ key }>
						<strong>{ label }</strong>
						<div>
							{ 0 === ranges.length && (
								<em>{ __( 'Non disponibile', 'wp-book-a-call' ) }</em>
							) }
							{ ranges.map( ( range, i ) => (
								<div className="wpbac-admin__range" key={ i }>
									<TextControl
										type="time"
										label={ __( 'Dalle', 'wp-book-a-call' ) }
										value={ range[ 0 ] }
										onChange={ ( v ) =>
											setDay(
												key,
												ranges.map( ( r, j ) => ( j === i ? [ v, r[ 1 ] ] : r ) )
											)
										}
									/>
									<TextControl
										type="time"
										label={ __( 'Alle', 'wp-book-a-call' ) }
										value={ range[ 1 ] }
										onChange={ ( v ) =>
											setDay(
												key,
												ranges.map( ( r, j ) => ( j === i ? [ r[ 0 ], v ] : r ) )
											)
										}
									/>
									<Button
										isDestructive
										variant="tertiary"
										onClick={ () => setDay( key, ranges.filter( ( _, j ) => j !== i ) ) }
									>
										×
									</Button>
								</div>
							) ) }
							<Button
								variant="link"
								onClick={ () => setDay( key, [ ...ranges, [ '09:00', '12:00' ] ] ) }
							>
								{ __( '+ Aggiungi fascia', 'wp-book-a-call' ) }
							</Button>
						</div>
					</Fragment>
				);
			} ) }
		</div>
	);
}

/** Editor delle domande personalizzate. */
function Questions( { value, onChange } ) {
	const update = ( i, patch ) =>
		onChange( value.map( ( q, j ) => ( j === i ? { ...q, ...patch } : q ) ) );

	return (
		<>
			{ value.map( ( q, i ) => (
				<div className="wpbac-admin__range" key={ i }>
					<TextControl
						label={ __( 'Domanda', 'wp-book-a-call' ) }
						value={ q.label }
						onChange={ ( v ) => update( i, { label: v } ) }
					/>
					<SelectControl
						label={ __( 'Tipo', 'wp-book-a-call' ) }
						value={ q.type }
						options={ [
							{ value: 'text', label: __( 'Riga singola', 'wp-book-a-call' ) },
							{ value: 'textarea', label: __( 'Testo lungo', 'wp-book-a-call' ) },
						] }
						onChange={ ( v ) => update( i, { type: v } ) }
					/>
					<ToggleControl
						label={ __( 'Obbligatoria', 'wp-book-a-call' ) }
						checked={ !! q.required }
						onChange={ ( v ) => update( i, { required: v } ) }
					/>
					<Button
						isDestructive
						variant="tertiary"
						onClick={ () => onChange( value.filter( ( _, j ) => j !== i ) ) }
					>
						×
					</Button>
				</div>
			) ) }
			<Button
				variant="link"
				onClick={ () =>
					onChange( [ ...value, { label: '', type: 'text', required: false } ] )
				}
			>
				{ __( '+ Aggiungi domanda', 'wp-book-a-call' ) }
			</Button>
		</>
	);
}

/** Form di modifica di un tipo di call. */
function Editor( { initial, onSaved, onCancel } ) {
	const [ form, setForm ] = useState( initial );
	const [ error, setError ] = useState( '' );
	const [ saving, setSaving ] = useState( false );

	const set = ( key ) => ( value ) => setForm( { ...form, [ key ]: value } );
	const num = ( key ) => ( value ) => set( key )( parseInt( value, 10 ) || 0 );

	const save = () => {
		setSaving( true );
		api( form.id ? `/admin/event-types/${ form.id }` : '/admin/event-types', {
			method: 'POST',
			data: form,
		} )
			.then( onSaved )
			.catch( ( e ) => {
				setError( e.message );
				setSaving( false );
			} );
	};

	return (
		<div className="wpbac-admin__card">
			{ error && <Notice status="error" isDismissible={ false }>{ error }</Notice> }
			<div className="wpbac-admin__row">
				<TextControl label={ __( 'Titolo', 'wp-book-a-call' ) } value={ form.title } onChange={ set( 'title' ) } />
				<TextControl
					label={ __( 'Slug', 'wp-book-a-call' ) }
					help={ __( 'Lascia vuoto per generarlo dal titolo.', 'wp-book-a-call' ) }
					value={ form.slug }
					onChange={ set( 'slug' ) }
				/>
			</div>
			<TextareaControl label={ __( 'Descrizione', 'wp-book-a-call' ) } value={ form.description } onChange={ set( 'description' ) } />
			<div className="wpbac-admin__row">
				<TextControl type="number" label={ __( 'Durata (minuti)', 'wp-book-a-call' ) } value={ form.duration_min } onChange={ num( 'duration_min' ) } />
				<TextControl type="number" label={ __( 'Intervallo tra slot (minuti)', 'wp-book-a-call' ) } value={ form.slot_step_min } onChange={ num( 'slot_step_min' ) } />
				<TextControl type="number" label={ __( 'Pausa prima (min)', 'wp-book-a-call' ) } value={ form.buffer_before_min } onChange={ num( 'buffer_before_min' ) } />
				<TextControl type="number" label={ __( 'Pausa dopo (min)', 'wp-book-a-call' ) } value={ form.buffer_after_min } onChange={ num( 'buffer_after_min' ) } />
			</div>
			<div className="wpbac-admin__row">
				<TextControl type="number" label={ __( 'Preavviso minimo (ore)', 'wp-book-a-call' ) } value={ form.min_notice_hours } onChange={ num( 'min_notice_hours' ) } />
				<TextControl type="number" label={ __( 'Prenotabile fino a (giorni)', 'wp-book-a-call' ) } value={ form.max_days_ahead } onChange={ num( 'max_days_ahead' ) } />
				<SelectControl
					label={ __( 'Dove', 'wp-book-a-call' ) }
					value={ form.location_type }
					options={ [
						{ value: 'meet', label: 'Google Meet' },
						{ value: 'phone', label: __( 'Telefono', 'wp-book-a-call' ) },
						{ value: 'custom', label: __( 'Altro (link o indirizzo)', 'wp-book-a-call' ) },
					] }
					onChange={ set( 'location_type' ) }
				/>
				<TextControl
					label={ __( 'Dettaglio luogo', 'wp-book-a-call' ) }
					help={ __( 'Numero di telefono, link o indirizzo.', 'wp-book-a-call' ) }
					value={ form.location_value }
					onChange={ set( 'location_value' ) }
				/>
			</div>
			<h3>{ __( 'Orari settimanali', 'wp-book-a-call' ) }</h3>
			<WeeklyHours value={ form.weekly_hours } onChange={ set( 'weekly_hours' ) } />
			<h3>{ __( 'Domande al cliente', 'wp-book-a-call' ) }</h3>
			<Questions value={ form.questions } onChange={ set( 'questions' ) } />
			<ToggleControl label={ __( 'Attivo', 'wp-book-a-call' ) } checked={ form.active } onChange={ set( 'active' ) } />
			<div className="wpbac-admin__actions">
				<Button variant="primary" isBusy={ saving } onClick={ save }>
					{ __( 'Salva', 'wp-book-a-call' ) }
				</Button>
				<Button variant="tertiary" onClick={ onCancel }>
					{ __( 'Chiudi', 'wp-book-a-call' ) }
				</Button>
			</div>
		</div>
	);
}

export default function EventTypes() {
	const [ types, setTypes ] = useState( null );
	const [ editing, setEditing ] = useState( null );
	const [ error, setError ] = useState( '' );

	const load = () => api( '/admin/event-types' ).then( setTypes );
	useEffect( () => {
		load();
	}, [] );

	const remove = ( t ) =>
		api( `/admin/event-types/${ t.id }`, { method: 'DELETE' } )
			.then( load )
			.catch( ( e ) => setError( e.message ) );

	if ( editing ) {
		return (
			<div className="wpbac-admin__panel">
				<Editor
					initial={ editing }
					onCancel={ () => setEditing( null ) }
					onSaved={ () => {
						setEditing( null );
						load();
					} }
				/>
			</div>
		);
	}

	return (
		<div className="wpbac-admin__panel">
			{ error && <Notice status="error" onRemove={ () => setError( '' ) }>{ error }</Notice> }
			<Button variant="primary" onClick={ () => setEditing( NEW_TYPE ) }>
				{ __( 'Nuovo tipo di call', 'wp-book-a-call' ) }
			</Button>
			{ null === types && <Spinner /> }
			{ types && (
				<table className="wpbac-admin__table" style={ { marginTop: 16 } }>
					<tbody>
						{ types.map( ( t ) => (
							<tr key={ t.id }>
								<td>
									<strong>{ t.title }</strong>
									{ ! t.active && <em> ({ __( 'disattivo', 'wp-book-a-call' ) })</em> }
									<br />
									<code>{ t.slug }</code> · { t.duration_min } min
								</td>
								<td>
									<Button variant="secondary" onClick={ () => setEditing( t ) }>
										{ __( 'Modifica', 'wp-book-a-call' ) }
									</Button>{ ' ' }
									<Button isDestructive variant="link" onClick={ () => remove( t ) }>
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
