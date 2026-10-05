import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
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
import { activeDays, defaultMap, firstRanges, formatDays } from '../availability';
import Availability from '../components/Availability';
import Actions from '../components/Actions';
import Section from '../components/Section';

const newType = () => ( {
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
	weekly_hours: defaultMap(),
	questions: [],
	active: true,
} );

const LOCATIONS = {
	meet: 'Google Meet',
	phone: __( 'Telefono', 'wp-book-a-call' ),
	custom: __( 'Altro', 'wp-book-a-call' ),
};

/** Regole principali di un tipo di call, su due righe: quelle impostate soltanto. */
const rules = ( t ) => {
	const spacing = [
		sprintf( __( 'Orari ogni %d min', 'wp-book-a-call' ), t.slot_step_min ),
		t.buffer_before_min > 0 && sprintf( __( 'pausa prima %d min', 'wp-book-a-call' ), t.buffer_before_min ),
		t.buffer_after_min > 0 && sprintf( __( 'pausa dopo %d min', 'wp-book-a-call' ), t.buffer_after_min ),
	];
	const limits = [
		sprintf( __( 'Preavviso %d h', 'wp-book-a-call' ), t.min_notice_hours ),
		sprintf( __( 'fino a %d giorni', 'wp-book-a-call' ), t.max_days_ahead ),
	];
	return [ spacing, limits ].map( ( line ) => line.filter( Boolean ).join( ' · ' ) );
};

/** Editor delle domande personalizzate. */
function Questions( { value, onChange } ) {
	const update = ( i, patch ) => onChange( value.map( ( q, j ) => ( j === i ? { ...q, ...patch } : q ) ) );

	return (
		<>
			{ 0 === value.length && (
				<p className="wpbac-admin__empty">
					{ __( 'Nessuna domanda: il cliente inserirà solo nome ed email.', 'wp-book-a-call' ) }
				</p>
			) }
			{ value.map( ( q, i ) => (
				<div className="wpbac-admin__question" key={ i }>
					<TextControl
						label={ __( 'Domanda', 'wp-book-a-call' ) }
						value={ q.label }
						onChange={ ( v ) => update( i, { label: v } ) }
						__nextHasNoMarginBottom
					/>
					<SelectControl
						label={ __( 'Tipo di risposta', 'wp-book-a-call' ) }
						value={ q.type }
						options={ [
							{ value: 'text', label: __( 'Riga singola', 'wp-book-a-call' ) },
							{ value: 'textarea', label: __( 'Testo lungo', 'wp-book-a-call' ) },
						] }
						onChange={ ( v ) => update( i, { type: v } ) }
						__nextHasNoMarginBottom
					/>
					<ToggleControl
						label={ __( 'Obbligatoria', 'wp-book-a-call' ) }
						checked={ !! q.required }
						onChange={ ( v ) => update( i, { required: v } ) }
						__nextHasNoMarginBottom
					/>
					<Button isDestructive variant="tertiary" onClick={ () => onChange( value.filter( ( _, j ) => j !== i ) ) }>
						{ __( 'Rimuovi', 'wp-book-a-call' ) }
					</Button>
				</div>
			) ) }
			<Actions>
				<Button variant="secondary" onClick={ () => onChange( [ ...value, { label: '', type: 'text', required: false } ] ) }>
					{ __( 'Aggiungi domanda', 'wp-book-a-call' ) }
				</Button>
			</Actions>
		</>
	);
}

/** Form di modifica di un tipo di call, diviso in sezioni. */
function Editor( { initial, onSaved, onCancel } ) {
	const [ form, setForm ] = useState( initial );
	const [ error, setError ] = useState( '' );
	const [ saving, setSaving ] = useState( false );

	const set = ( key ) => ( value ) => setForm( { ...form, [ key ]: value } );
	const num = ( key ) => ( value ) => set( key )( parseInt( value, 10 ) || 0 );

	const save = () => {
		setSaving( true );
		api( form.id ? `/admin/event-types/${ form.id }` : '/admin/event-types', { method: 'POST', data: form } )
			.then( onSaved )
			.catch( ( e ) => {
				setError( e.message );
				setSaving( false );
			} );
	};

	return (
		<div className="wpbac-admin__panel">
			{ error && (
				<Notice status="error" onRemove={ () => setError( '' ) }>
					{ error }
				</Notice>
			) }

			<Section title={ __( 'Generale', 'wp-book-a-call' ) } description={ __( 'Nome, descrizione e luogo della call.', 'wp-book-a-call' ) }>
				<div className="wpbac-admin__grid">
					<TextControl label={ __( 'Titolo', 'wp-book-a-call' ) } value={ form.title } onChange={ set( 'title' ) } />
					<TextControl
						label={ __( 'Slug', 'wp-book-a-call' ) }
						help={ __( 'Lascia vuoto per generarlo dal titolo.', 'wp-book-a-call' ) }
						value={ form.slug }
						onChange={ set( 'slug' ) }
					/>
					<TextControl type="number" label={ __( 'Durata (minuti)', 'wp-book-a-call' ) } value={ form.duration_min } onChange={ num( 'duration_min' ) } />
					<SelectControl
						label={ __( 'Dove', 'wp-book-a-call' ) }
						value={ form.location_type }
						options={ Object.entries( LOCATIONS ).map( ( [ value, label ] ) => ( { value, label } ) ) }
						onChange={ set( 'location_type' ) }
					/>
				</div>
				<TextareaControl label={ __( 'Descrizione', 'wp-book-a-call' ) } value={ form.description } onChange={ set( 'description' ) } />
				{ 'meet' !== form.location_type && (
					<TextControl
						label={ __( 'Dettaglio luogo', 'wp-book-a-call' ) }
						help={ __( 'Numero di telefono, link o indirizzo.', 'wp-book-a-call' ) }
						value={ form.location_value }
						onChange={ set( 'location_value' ) }
					/>
				) }
			</Section>

			<Section title={ __( 'Disponibilità', 'wp-book-a-call' ) } description={ __( 'Scegli i giorni e le fasce orarie: valgono per tutti i giorni selezionati.', 'wp-book-a-call' ) }>
				<Availability value={ form.weekly_hours } onChange={ set( 'weekly_hours' ) } />
			</Section>

			<Section title={ __( 'Domande al cliente', 'wp-book-a-call' ) } description={ __( 'Campi aggiuntivi nel modulo di prenotazione.', 'wp-book-a-call' ) }>
				<Questions value={ form.questions } onChange={ set( 'questions' ) } />
			</Section>

			<Section title={ __( 'Avanzate', 'wp-book-a-call' ) } description={ __( 'Intervalli tra gli orari, pause, preavviso e orizzonte di prenotazione.', 'wp-book-a-call' ) }>
				<div className="wpbac-admin__grid">
					<TextControl type="number" label={ __( 'Intervallo tra gli orari (minuti)', 'wp-book-a-call' ) } value={ form.slot_step_min } onChange={ num( 'slot_step_min' ) } />
					<TextControl type="number" label={ __( 'Preavviso minimo (ore)', 'wp-book-a-call' ) } value={ form.min_notice_hours } onChange={ num( 'min_notice_hours' ) } />
					<TextControl type="number" label={ __( 'Pausa prima (minuti)', 'wp-book-a-call' ) } value={ form.buffer_before_min } onChange={ num( 'buffer_before_min' ) } />
					<TextControl type="number" label={ __( 'Pausa dopo (minuti)', 'wp-book-a-call' ) } value={ form.buffer_after_min } onChange={ num( 'buffer_after_min' ) } />
					<TextControl type="number" label={ __( 'Prenotabile fino a (giorni)', 'wp-book-a-call' ) } value={ form.max_days_ahead } onChange={ num( 'max_days_ahead' ) } />
				</div>
				<ToggleControl label={ __( 'Attivo (prenotabile dal sito)', 'wp-book-a-call' ) } checked={ form.active } onChange={ set( 'active' ) } />
			</Section>

			<div className="wpbac-admin__actions">
				<Button variant="primary" isBusy={ saving } onClick={ save }>
					{ __( 'Salva', 'wp-book-a-call' ) }
				</Button>
				<Button variant="tertiary" onClick={ onCancel }>
					{ __( 'Torna all\'elenco', 'wp-book-a-call' ) }
				</Button>
			</div>
		</div>
	);
}

export default function EventTypes() {
	const [ types, setTypes ] = useState( null );
	const [ editing, setEditing ] = useState( null );
	const [ confirmId, setConfirmId ] = useState( 0 );
	const [ error, setError ] = useState( '' );

	const load = () => api( '/admin/event-types' ).then( setTypes );
	useEffect( () => {
		load();
	}, [] );

	// Doppio clic per eliminare.
	const remove = ( t ) => {
		if ( confirmId !== t.id ) {
			setConfirmId( t.id );
			return;
		}
		api( `/admin/event-types/${ t.id }`, { method: 'DELETE' } )
			.then( () => {
				setConfirmId( 0 );
				load();
			} )
			.catch( ( e ) => setError( e.message ) );
	};

	// Duplica: stessa configurazione, senza id né slug.
	const duplicate = ( t ) => {
		const { id, slug, created_at, updated_at, ...copy } = t; // eslint-disable-line no-unused-vars
		setEditing( { ...copy, slug: '', title: `${ t.title } (${ __( 'copia', 'wp-book-a-call' ) })` } );
	};

	if ( editing ) {
		return (
			<Editor
				initial={ editing }
				onCancel={ () => setEditing( null ) }
				onSaved={ () => {
					setEditing( null );
					load();
				} }
			/>
		);
	}

	return (
		<div className="wpbac-admin__panel">
			{ error && (
				<Notice status="error" onRemove={ () => setError( '' ) }>
					{ error }
				</Notice>
			) }
			<Section
				title={ __( 'Tipi di call', 'wp-book-a-call' ) }
				description={ __( 'Ogni tipo di call ha durata, disponibilità e domande proprie. Il blocco nella pagina mostra quello scelto.', 'wp-book-a-call' ) }
			>
				<Actions>
					<Button variant="primary" onClick={ () => setEditing( newType() ) }>
						{ __( 'Nuovo tipo di call', 'wp-book-a-call' ) }
					</Button>
				</Actions>
				{ null === types && <Spinner /> }
				{ types && 0 === types.length && (
					<p className="wpbac-admin__empty">{ __( 'Nessun tipo di call: creane uno per attivare le prenotazioni.', 'wp-book-a-call' ) }</p>
				) }
				{ types && types.length > 0 && (
					<table className="wp-list-table widefat striped wpbac-admin__table wpbac-admin__table--types">
						<thead>
							<tr>
								<th>{ __( 'Tipo di call', 'wp-book-a-call' ) }</th>
								<th>{ __( 'Durata', 'wp-book-a-call' ) }</th>
								<th>{ __( 'Disponibilità', 'wp-book-a-call' ) }</th>
								<th>{ __( 'Luogo', 'wp-book-a-call' ) }</th>
								<th>{ __( 'Regole', 'wp-book-a-call' ) }</th>
								<th>{ __( 'Stato', 'wp-book-a-call' ) }</th>
								<th />
							</tr>
						</thead>
						<tbody>
							{ types.map( ( t ) => (
								<tr key={ t.id }>
									<td>
										<strong>{ t.title }</strong>
										<br />
										<code>{ t.slug }</code>
									</td>
									<td>{ t.duration_min } min</td>
									<td>
										<strong>{ formatDays( activeDays( t.weekly_hours ) ) }</strong>
										<br />
										<span className="wpbac-admin__meta">{ firstRanges( t.weekly_hours ).map( ( r ) => `${ r[ 0 ] }-${ r[ 1 ] }` ).join( ', ' ) || '–' }</span>
									</td>
									<td>{ LOCATIONS[ t.location_type ] }</td>
									<td>
										<ul className="wpbac-admin__rules">
											{ rules( t ).map( ( line ) => (
												<li key={ line }>{ line }</li>
											) ) }
										</ul>
									</td>
									<td>
										<span className={ `wpbac-admin__badge ${ t.active ? 'is-confirmed' : 'is-cancelled' }` }>
											{ t.active ? __( 'Attivo', 'wp-book-a-call' ) : __( 'Disattivo', 'wp-book-a-call' ) }
										</span>
									</td>
									<td>
										<div className="wpbac-admin__row-actions">
											<Button variant="secondary" size="compact" onClick={ () => setEditing( t ) }>
												{ __( 'Modifica', 'wp-book-a-call' ) }
											</Button>
											<Button variant="tertiary" size="compact" onClick={ () => duplicate( t ) }>
												{ __( 'Duplica', 'wp-book-a-call' ) }
											</Button>
											<Button variant="tertiary" size="compact" isDestructive onClick={ () => remove( t ) }>
												{ confirmId === t.id ? __( 'Confermi?', 'wp-book-a-call' ) : __( 'Elimina', 'wp-book-a-call' ) }
											</Button>
										</div>
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
