import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
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
				// Dopo il salvataggio di una chiave Resend l'esito della prova ha la precedenza sul messaggio generico.
				if ( data?.resend_test ) {
					setNotice(
						data.resend_test.ok
							? { status: data.resend_test.warning ? 'warning' : 'success', text: data.resend_test.message }
							: {
									status: 'error',
									text: sprintf(
										/* translators: %s: errore restituito da Resend. */
										__( 'Resend non funziona: %s. Le email useranno WordPress finché non risolvi.', 'wp-book-a-call' ),
										data.resend_test.message
									),
							  }
					);
					return;
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
