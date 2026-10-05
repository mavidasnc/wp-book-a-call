import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { api } from '../api';

/**
 * Carica e salva le impostazioni del plugin.
 * Lo usano più schede (Impostazioni, Notifiche): ognuna ha il proprio stato e il proprio pulsante Salva.
 */
export default function useSettings() {
	const [ s, setS ] = useState( null );
	const [ notice, setNotice ] = useState( null );
	const [ saving, setSaving ] = useState( false );

	useEffect( () => {
		api( '/admin/settings' )
			.then( setS )
			.catch( ( e ) => setNotice( { status: 'error', text: e.message } ) );
	}, [] );

	/** Aggiorna un campo: `onChange={ set( 'chiave' ) }`. */
	const set = ( key ) => ( value ) => setS( ( current ) => ( { ...current, [ key ]: value } ) );

	/** Esegue una chiamata e mostra l'esito; se la risposta contiene le impostazioni le aggiorna. */
	const run = ( promise, okText ) =>
		promise
			.then( ( data ) => {
				if ( data && undefined !== data.google_connected ) {
					setS( data );
				}
				setNotice( { status: 'success', text: okText } );
			} )
			.catch( ( e ) => setNotice( { status: 'error', text: e.message } ) );

	const save = () => {
		setSaving( true );
		run( api( '/admin/settings', { method: 'POST', data: s } ), __( 'Impostazioni salvate.', 'wp-book-a-call' ) ).finally( () => setSaving( false ) );
	};

	return { s, set, notice, setNotice, saving, save, run };
}
