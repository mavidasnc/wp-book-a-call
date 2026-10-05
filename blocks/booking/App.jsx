import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import Picker from './Picker';
import {
	call,
	fmtDate,
	fmtTime,
	googleCalendarUrl,
	icsUrl,
	timezoneList,
} from './utils';

/** Riepilogo data/ora/fuso di una prenotazione. */
function When( { start, tz } ) {
	return (
		<p className="wpbac-booking__when">
			<strong>{ fmtDate( start, tz ) }</strong>
			<br />
			{ fmtTime( start, tz ) } ({ tz })
		</p>
	);
}

/** Form dei dati del cliente. */
function Form( { config, start, tz, onBack, onDone } ) {
	const { event, privacyUrl, apiRoot } = config;
	const startedAt = useRef( Date.now() );
	const [ values, setValues ] = useState( { name: '', email: '', website: '', privacy: false, answers: {} } );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( false );

	const submit = ( e ) => {
		e.preventDefault();
		setBusy( true );
		setError( '' );
		call( apiRoot, '/bookings', {
			method: 'POST',
			body: {
				slug: event.slug,
				start,
				timezone: tz,
				name: values.name,
				email: values.email,
				answers: values.answers,
				privacy: values.privacy,
				website: values.website,
				elapsed_ms: Date.now() - startedAt.current,
				page_url: window.location.origin + window.location.pathname,
			},
		} )
			.then( onDone )
			.catch( ( err ) => {
				setError( err.message );
				setBusy( false );
				// Slot occupato nel frattempo: si torna al calendario.
				if ( 409 === err.status ) {
					setTimeout( onBack, 2500 );
				}
			} );
	};

	return (
		<form className="wpbac-booking__form" onSubmit={ submit }>
			<When start={ start } tz={ tz } />
			<label>
				{ __( 'Nome e cognome', 'wp-book-a-call' ) }
				<input type="text" required autoComplete="name" value={ values.name } onChange={ ( e ) => setValues( { ...values, name: e.target.value } ) } />
			</label>
			<label>
				{ __( 'Email', 'wp-book-a-call' ) }
				<input type="email" required autoComplete="email" value={ values.email } onChange={ ( e ) => setValues( { ...values, email: e.target.value } ) } />
			</label>
			{ event.questions.map( ( q ) => (
				<label key={ q.id }>
					{ q.label }
					{ q.required ? ' *' : '' }
					{ 'textarea' === q.type ? (
						<textarea rows="3" required={ q.required } value={ values.answers[ q.id ] ?? '' } onChange={ ( e ) => setValues( { ...values, answers: { ...values.answers, [ q.id ]: e.target.value } } ) } />
					) : (
						<input type="text" required={ q.required } value={ values.answers[ q.id ] ?? '' } onChange={ ( e ) => setValues( { ...values, answers: { ...values.answers, [ q.id ]: e.target.value } } ) } />
					) }
				</label>
			) ) }
			{ /* Honeypot: invisibile agli umani, i bot lo compilano. */ }
			<div className="wpbac-booking__hp" aria-hidden="true">
				<label>
					Website
					<input type="text" tabIndex="-1" autoComplete="off" value={ values.website } onChange={ ( e ) => setValues( { ...values, website: e.target.value } ) } />
				</label>
			</div>
			<label className="wpbac-booking__check">
				<input type="checkbox" required checked={ values.privacy } onChange={ ( e ) => setValues( { ...values, privacy: e.target.checked } ) } />
				<span>
					{ __( 'Ho letto e accetto l\'informativa sulla privacy', 'wp-book-a-call' ) }
					{ privacyUrl && (
						<>
							{ ' ' }
							(<a href={ privacyUrl } target="_blank" rel="noreferrer">{ __( 'leggi', 'wp-book-a-call' ) }</a>)
						</>
					) }
				</span>
			</label>
			{ error && <p className="wpbac-booking__error" role="alert">{ error }</p> }
			<div className="wpbac-booking__buttons">
				<button type="button" className="wpbac-booking__secondary" onClick={ onBack }>
					{ __( 'Indietro', 'wp-book-a-call' ) }
				</button>
				<button type="submit" className="wpbac-booking__primary" disabled={ busy }>
					{ busy ? __( 'Invio…', 'wp-book-a-call' ) : __( 'Conferma prenotazione', 'wp-book-a-call' ) }
				</button>
			</div>
		</form>
	);
}

/** Conferma finale con link al calendario. */
function Done( { booking, tz, config } ) {
	const title = `${ booking.event.title } - ${ config.hostName }`;
	const data = {
		id: booking.id,
		title,
		start: booking.start,
		end: booking.end,
		location: booking.meet_url,
	};
	return (
		<div className="wpbac-booking__done" role="status">
			<h3>{ __( 'Prenotazione confermata', 'wp-book-a-call' ) }</h3>
			<When start={ booking.start } tz={ tz } />
			<p>
				{ __( 'Ti ho inviato una email di conferma con l\'invito per il calendario.', 'wp-book-a-call' ) }
			</p>
			{ booking.meet_url && (
				<p>
					<a href={ booking.meet_url } target="_blank" rel="noreferrer">{ __( 'Link Google Meet', 'wp-book-a-call' ) }</a>
				</p>
			) }
			<div className="wpbac-booking__buttons">
				<a className="wpbac-booking__secondary" href={ googleCalendarUrl( data ) } target="_blank" rel="noreferrer">
					{ __( 'Aggiungi a Google Calendar', 'wp-book-a-call' ) }
				</a>
				<a className="wpbac-booking__secondary" href={ icsUrl( data ) } download="invito.ics">
					{ __( 'Scarica .ics', 'wp-book-a-call' ) }
				</a>
			</div>
		</div>
	);
}

/** Vista di gestione (dal link nell'email): sposta o annulla. */
function Manage( { config, id, token, tz } ) {
	const [ booking, setBooking ] = useState( null );
	const [ mode, setMode ] = useState( 'view' );
	const [ confirm, setConfirm ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ message, setMessage ] = useState( '' );

	useEffect( () => {
		call( config.apiRoot, `/manage/${ id }`, { params: { token } } )
			.then( setBooking )
			.catch( ( e ) => setError( e.message ) );
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

	const cancel = () => {
		if ( ! confirm ) {
			setConfirm( true );
			return;
		}
		call( config.apiRoot, `/manage/${ id }/cancel`, { method: 'POST', body: { token } } )
			.then( ( b ) => {
				setBooking( b );
				setMessage( __( 'La prenotazione è stata annullata.', 'wp-book-a-call' ) );
			} )
			.catch( ( e ) => setError( e.message ) );
	};

	const reschedule = ( start ) =>
		call( config.apiRoot, `/manage/${ id }/reschedule`, { method: 'POST', body: { token, start } } )
			.then( ( b ) => {
				setBooking( b );
				setMode( 'view' );
				setMessage( __( 'Prenotazione spostata. Ti ho inviato l\'invito aggiornato.', 'wp-book-a-call' ) );
			} )
			.catch( ( e ) => setError( e.message ) );

	if ( error && ! booking ) {
		return <p className="wpbac-booking__error" role="alert">{ error }</p>;
	}
	if ( ! booking ) {
		return <p className="wpbac-booking__hint">{ __( 'Caricamento…', 'wp-book-a-call' ) }</p>;
	}

	if ( 'reschedule' === mode ) {
		return (
			<>
				<h3>{ __( 'Scegli il nuovo orario', 'wp-book-a-call' ) }</h3>
				{ error && <p className="wpbac-booking__error" role="alert">{ error }</p> }
				<Picker apiRoot={ config.apiRoot } slug={ booking.event.slug } tz={ tz } onSelect={ reschedule } />
				<button type="button" className="wpbac-booking__secondary" onClick={ () => setMode( 'view' ) }>
					{ __( 'Indietro', 'wp-book-a-call' ) }
				</button>
			</>
		);
	}

	return (
		<div className="wpbac-booking__done">
			<h3>{ booking.event.title }</h3>
			{ message && <p role="status">{ message }</p> }
			{ booking.cancelled ? (
				<p><em>{ __( 'Prenotazione annullata.', 'wp-book-a-call' ) }</em></p>
			) : (
				<>
					<When start={ booking.start } tz={ tz } />
					<div className="wpbac-booking__buttons">
						<button type="button" className="wpbac-booking__secondary" onClick={ () => setMode( 'reschedule' ) }>
							{ __( 'Sposta', 'wp-book-a-call' ) }
						</button>
						<button type="button" className="wpbac-booking__secondary" onClick={ cancel }>
							{ confirm ? __( 'Confermi l\'annullamento?', 'wp-book-a-call' ) : __( 'Annulla prenotazione', 'wp-book-a-call' ) }
						</button>
					</div>
				</>
			) }
			{ error && <p className="wpbac-booking__error" role="alert">{ error }</p> }
		</div>
	);
}

export default function App( { config } ) {
	const { event, hostName, showHostInfo, showTimezoneSelector, siteTimezone } = config;
	const params = new URLSearchParams( window.location.search );
	const manageId = params.get( 'wpbac_booking' );
	const manageToken = params.get( 'wpbac_token' );

	const [ tz, setTz ] = useState( Intl.DateTimeFormat().resolvedOptions().timeZone || siteTimezone );
	const [ start, setStart ] = useState( 0 );
	const [ done, setDone ] = useState( null );

	let content;
	if ( manageId && manageToken ) {
		content = <Manage config={ config } id={ manageId } token={ manageToken } tz={ tz } />;
	} else if ( done ) {
		content = <Done booking={ done } tz={ tz } config={ config } />;
	} else if ( start ) {
		content = <Form config={ config } start={ start } tz={ tz } onBack={ () => setStart( 0 ) } onDone={ setDone } />;
	} else {
		content = <Picker apiRoot={ config.apiRoot } slug={ event.slug } tz={ tz } onSelect={ setStart } />;
	}

	return (
		<div className="wpbac-booking__card">
			<aside className="wpbac-booking__info">
				{ showHostInfo && <p className="wpbac-booking__host">{ hostName }</p> }
				<h2 className="wpbac-booking__title">{ event.title }</h2>
				<p className="wpbac-booking__duration">
					{ event.duration_min } { __( 'minuti', 'wp-book-a-call' ) }
					{ 'meet' === event.location_type && ' · Google Meet' }
				</p>
				{ event.description && <div className="wpbac-booking__description" dangerouslySetInnerHTML={ { __html: event.description } } /> }
				{ showTimezoneSelector && (
					<label className="wpbac-booking__tz">
						{ __( 'Fuso orario', 'wp-book-a-call' ) }
						<select value={ tz } onChange={ ( e ) => setTz( e.target.value ) }>
							{ timezoneList( tz, siteTimezone ).map( ( z ) => (
								<option key={ z } value={ z }>{ z }</option>
							) ) }
						</select>
					</label>
				) }
			</aside>
			<div className="wpbac-booking__main">{ content }</div>
		</div>
	);
}
