import { __ } from '@wordpress/i18n';

/** Giorni della settimana: chiave usata dal backend e etichetta breve. */
export const DAYS = [
	[ 'mon', __( 'Lun', 'wp-book-a-call' ) ],
	[ 'tue', __( 'Mar', 'wp-book-a-call' ) ],
	[ 'wed', __( 'Mer', 'wp-book-a-call' ) ],
	[ 'thu', __( 'Gio', 'wp-book-a-call' ) ],
	[ 'fri', __( 'Ven', 'wp-book-a-call' ) ],
	[ 'sat', __( 'Sab', 'wp-book-a-call' ) ],
	[ 'sun', __( 'Dom', 'wp-book-a-call' ) ],
];

const KEYS = DAYS.map( ( [ key ] ) => key );

/** Fasce orarie di default per un nuovo tipo di call. */
export const DEFAULT_RANGES = [
	[ '10:00', '12:30' ],
	[ '15:00', '18:00' ],
];

/** Giorni con almeno una fascia nella mappa del backend. */
export const activeDays = ( map ) =>
	KEYS.filter( ( key ) => ( map?.[ key ] ?? [] ).length > 0 );

/** Fasce comuni: quelle del primo giorno attivo (il backend le tiene per giorno). */
export const firstRanges = ( map ) => {
	const [ first ] = activeDays( map );
	return first ? map[ first ] : [];
};

/** Costruisce la mappa per giorno assegnando le stesse fasce ai giorni attivi. */
export const buildMap = ( days, ranges ) =>
	Object.fromEntries( KEYS.map( ( key ) => [ key, days.includes( key ) ? ranges : [] ] ) );

/** Mappa di default: lun-ven con le fasce di default. */
export const defaultMap = () => buildMap( KEYS.slice( 0, 5 ), DEFAULT_RANGES );

/** Testo compatto dei giorni attivi, es. "Lun-Ven" o "Lun, Mer, Sab-Dom". */
export const formatDays = ( days ) => {
	const idx = KEYS.map( ( key, i ) => ( days.includes( key ) ? i : -1 ) ).filter( ( i ) => i >= 0 );
	if ( ! idx.length ) {
		return __( 'Nessun giorno', 'wp-book-a-call' );
	}

	// Raggruppa gli indici consecutivi: [0,1,2,4] -> [[0,2],[4,4]].
	const groups = [];
	idx.forEach( ( i ) => {
		const last = groups[ groups.length - 1 ];
		if ( last && i === last[ 1 ] + 1 ) {
			last[ 1 ] = i;
		} else {
			groups.push( [ i, i ] );
		}
	} );

	return groups
		.map( ( [ from, to ] ) =>
			from === to
				? DAYS[ from ][ 1 ]
				: to - from === 1
				? `${ DAYS[ from ][ 1 ] }, ${ DAYS[ to ][ 1 ] }`
				: `${ DAYS[ from ][ 1 ] }-${ DAYS[ to ][ 1 ] }`
		)
		.join( ', ' );
};
