import apiFetch from '@wordpress/api-fetch';

const { apiRoot, nonce, siteTimezone } = window.wpbacAdmin;

// Il nonce wp_rest autentica le chiamate con il cookie di sessione dell'admin.
apiFetch.use( apiFetch.createNonceMiddleware( nonce ) );

/**
 * Chiamata alla REST API del plugin.
 *
 * @param {string} path    Percorso dopo wpbac/v1.
 * @param {Object} options Opzioni di apiFetch (method, data).
 */
export const api = ( path, options = {} ) =>
	apiFetch( { url: apiRoot + path, ...options } );

export { siteTimezone };

/**
 * Formatta un timestamp UNIX nel fuso del sito.
 *
 * @param {number} ts Timestamp in secondi.
 */
export const formatDate = ( ts ) =>
	new Date( ts * 1000 ).toLocaleString( 'it-IT', {
		timeZone: siteTimezone,
		dateStyle: 'medium',
		timeStyle: 'short',
	} );

/**
 * Avvia il download di un testo come file (il CSV arriva dal server già pronto).
 *
 * @param {string} filename Nome del file.
 * @param {string} content  Contenuto.
 * @param {string} type     Tipo MIME.
 */
export const downloadFile = ( filename, content, type = 'text/csv;charset=utf-8' ) => {
	const url = URL.createObjectURL( new Blob( [ content ], { type } ) );
	const link = document.createElement( 'a' );
	link.href = url;
	link.download = filename;
	document.body.appendChild( link );
	link.click();
	link.remove();
	URL.revokeObjectURL( url );
};
