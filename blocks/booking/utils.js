/**
 * Helper di formattazione e rete per il widget di prenotazione.
 */

/** Chiave YYYY-MM-DD di un timestamp (secondi) nel fuso indicato. */
export const dayKey = ( ts, tz ) =>
	new Intl.DateTimeFormat( 'en-CA', {
		timeZone: tz,
		year: 'numeric',
		month: '2-digit',
		day: '2-digit',
	} ).format( new Date( ts * 1000 ) );

/** Orario HH:MM di un timestamp nel fuso indicato. */
export const fmtTime = ( ts, tz ) =>
	new Intl.DateTimeFormat( 'it-IT', {
		timeZone: tz,
		hour: '2-digit',
		minute: '2-digit',
	} ).format( new Date( ts * 1000 ) );

/** Data estesa di un timestamp nel fuso indicato. */
export const fmtDate = ( ts, tz ) =>
	new Intl.DateTimeFormat( 'it-IT', {
		timeZone: tz,
		weekday: 'long',
		day: 'numeric',
		month: 'long',
		year: 'numeric',
	} ).format( new Date( ts * 1000 ) );

/** Elenco dei fusi orari disponibili nel browser. */
export const timezoneList = ( current, site ) => {
	const list =
		typeof Intl.supportedValuesOf === 'function'
			? Intl.supportedValuesOf( 'timeZone' )
			: [];
	return Array.from( new Set( [ current, site, ...list ] ) );
};

const compact = ( ts ) =>
	new Date( ts * 1000 ).toISOString().replace( /[-:]|\.\d{3}/g, '' );

/** Link "Aggiungi a Google Calendar". */
export const googleCalendarUrl = ( { title, start, end, location, details } ) =>
	'https://calendar.google.com/calendar/render?' +
	new URLSearchParams( {
		action: 'TEMPLATE',
		text: title,
		dates: `${ compact( start ) }/${ compact( end ) }`,
		location: location || '',
		details: details || '',
	} ).toString();

/** URL di un file .ics (Blob) per l'evento. */
export const icsUrl = ( { id, title, start, end, location } ) => {
	const esc = ( t ) => String( t ).replace( /[\\;,]/g, '\\$&' ).replace( /\n/g, '\\n' );
	const body = [
		'BEGIN:VCALENDAR',
		'VERSION:2.0',
		'PRODID:-//Mavida//WP Book a Call//IT',
		'BEGIN:VEVENT',
		`UID:wpbac-${ id }@${ window.location.hostname }`,
		`DTSTAMP:${ compact( Math.floor( Date.now() / 1000 ) ) }`,
		`DTSTART:${ compact( start ) }`,
		`DTEND:${ compact( end ) }`,
		`SUMMARY:${ esc( title ) }`,
		location ? `LOCATION:${ esc( location ) }` : '',
		'END:VEVENT',
		'END:VCALENDAR',
	]
		.filter( Boolean )
		.join( '\r\n' );
	return URL.createObjectURL( new Blob( [ body ], { type: 'text/calendar' } ) );
};

/**
 * Chiamata alla REST API pubblica (senza nonce: le pagine possono essere in cache).
 *
 * @param {string} root   apiRoot del plugin.
 * @param {string} path   Percorso dopo wpbac/v1.
 * @param {Object} opts   method, params (query string), body (JSON).
 */
export const call = async ( root, path, { method = 'GET', params = {}, body } = {} ) => {
	// URL costruito con URL() per funzionare anche con i permalink semplici (?rest_route=).
	const url = new URL( root + path, window.location.href );
	Object.entries( params ).forEach( ( [ k, v ] ) => url.searchParams.set( k, v ) );

	const res = await fetch( url.toString(), {
		method,
		credentials: 'omit',
		headers: body ? { 'Content-Type': 'application/json' } : {},
		body: body ? JSON.stringify( body ) : undefined,
	} );
	const data = await res.json().catch( () => ( {} ) );
	if ( ! res.ok ) {
		const error = new Error( data.message || 'Errore di rete' );
		error.status = res.status;
		throw error;
	}
	return data;
};
