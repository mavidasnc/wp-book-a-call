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
