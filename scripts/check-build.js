/**
 * Controlla che i file compilati (build/) esistano e che il codice PHP li agganci davvero.
 *
 * Nasce da un errore reale: il CSS dell'admin veniva compilato ma nessun codice PHP lo metteva in coda,
 * quindi la pagina appariva senza stile. Qui si verifica che:
 *  - i file attesi in build/ esistano e non siano vuoti;
 *  - ogni percorso `build/...` citato nel codice PHP esista;
 *  - ogni file indicato come `file:./...` in build/booking/block.json esista.
 *
 * Uso: node scripts/check-build.js (dopo `npm run build`)
 */

const fs = require( 'fs' );
const path = require( 'path' );

const ROOT = path.resolve( __dirname, '..' );
const errors = [];

const exists = ( rel ) => fs.existsSync( path.join( ROOT, rel ) );
const nonEmpty = ( rel ) => exists( rel ) && fs.statSync( path.join( ROOT, rel ) ).size > 0;

// 1) File attesi dopo la build.
[
	'build/admin/index.js',
	'build/admin/index.css',
	'build/admin/index.asset.php',
	'build/booking/block.json',
	'build/booking/index.js',
	'build/booking/view.js',
	'build/booking/view.asset.php',
	'build/booking/style-index.css',
	'build/booking/render.php',
].forEach( ( file ) => {
	if ( ! nonEmpty( file ) ) {
		errors.push( `File compilato mancante o vuoto: ${ file }` );
	}
} );

// 2) Percorsi build/... citati nel PHP (script, stili, directory dei blocchi).
const phpFiles = [];
const walk = ( dir ) => {
	for ( const entry of fs.readdirSync( path.join( ROOT, dir ), { withFileTypes: true } ) ) {
		const rel = path.posix.join( dir, entry.name );
		if ( entry.isDirectory() ) {
			walk( rel );
		} else if ( entry.name.endsWith( '.php' ) ) {
			phpFiles.push( rel );
		}
	}
};
walk( 'src' );
phpFiles.push( 'wp-book-a-call.php' );

phpFiles.forEach( ( file ) => {
	const source = fs.readFileSync( path.join( ROOT, file ), 'utf8' );
	for ( const match of source.matchAll( /'(build\/[^']+)'/g ) ) {
		if ( ! exists( match[ 1 ] ) ) {
			errors.push( `${ file } cita ${ match[ 1 ] }, che non esiste dopo la build` );
		}
	}
} );

// 3) L'admin deve agganciare sia lo script sia il CSS compilati.
const adminMenu = fs.readFileSync( path.join( ROOT, 'src/Admin/AdminMenu.php' ), 'utf8' );
[ 'build/admin/index.js', 'build/admin/index.css' ].forEach( ( file ) => {
	if ( ! adminMenu.includes( `'${ file }'` ) ) {
		errors.push( `src/Admin/AdminMenu.php non aggancia ${ file }` );
	}
} );

// 4) File indicati in block.json.
if ( exists( 'build/booking/block.json' ) ) {
	const block = JSON.parse( fs.readFileSync( path.join( ROOT, 'build/booking/block.json' ), 'utf8' ) );
	[ 'editorScript', 'editorStyle', 'style', 'viewScript', 'render' ].forEach( ( key ) => {
		const value = block[ key ];
		if ( typeof value === 'string' && value.startsWith( 'file:' ) ) {
			const rel = path.posix.join( 'build/booking', value.slice( 7 ) );
			if ( ! exists( rel ) ) {
				errors.push( `block.json: ${ key } punta a ${ rel }, che non esiste` );
			}
		}
	} );
}

if ( errors.length ) {
	console.error( '\x1b[31m✗ Controllo dei file compilati fallito:\x1b[0m' );
	errors.forEach( ( e ) => console.error( `  - ${ e }` ) );
	process.exit( 1 );
}
console.log( '\x1b[32m✓\x1b[0m File compilati e aggancio PHP verificati.' );
