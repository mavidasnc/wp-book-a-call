import { createRoot } from '@wordpress/element';
import App from './App';

// Monta il widget in ogni istanza del blocco presente nella pagina.
document.querySelectorAll( '.wpbac-booking__root' ).forEach( ( el ) => {
	try {
		const config = JSON.parse( el.dataset.config );
		createRoot( el ).render( <App config={ config } /> );
	} catch ( e ) {
		// Config assente o non valida: si lascia il contenuto <noscript> di render.php.
	}
} );
