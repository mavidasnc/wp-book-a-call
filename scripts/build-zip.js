/**
 * Crea un archivio ZIP distribuibile del plugin.
 *
 * Uso: npm run build:zip
 * Output: zip/wp-book-a-call-{version}.zip
 *
 * Da eseguire DOPO `npm run build` e `composer install --no-dev` (lo fa `npm run build:zip`).
 */

const archiver = require( 'archiver' );
const fs = require( 'fs' );
const path = require( 'path' );

const ROOT = path.resolve( __dirname, '..' );
const pkg = require( path.join( ROOT, 'package.json' ) );
const SLUG = 'wp-book-a-call';
const ZIP_DIR = path.join( ROOT, 'zip' );
const ZIP_OUT = path.join( ZIP_DIR, `${ SLUG }-${ pkg.version }.zip` );

// File singoli nella root del plugin.
const FILES = [ `${ SLUG }.php`, 'uninstall.php', '.htaccess' ];

// Cartelle incluse (ricorsive).
const DIRS = [ 'src', 'build', 'vendor', 'languages', 'templates' ];

fs.mkdirSync( ZIP_DIR, { recursive: true } );

const output = fs.createWriteStream( ZIP_OUT );
const archive = archiver( 'zip', { zlib: { level: 9 } } );

output.on( 'close', () => {
	console.log( `\nZIP creato: zip/${ SLUG }-${ pkg.version }.zip (${ Math.round( archive.pointer() / 1024 ) } KB)\n` );
} );
archive.on( 'warning', ( err ) => console.warn( 'Attenzione:', err.message ) );
archive.on( 'error', ( err ) => {
	throw err;
} );
archive.pipe( output );

FILES.forEach( ( file ) => archive.file( path.join( ROOT, file ), { name: `${ SLUG }/${ file }` } ) );
DIRS.forEach( ( dir ) => {
	const full = path.join( ROOT, dir );
	if ( fs.existsSync( full ) ) {
		archive.directory( full, `${ SLUG }/${ dir }` );
	}
} );

archive.finalize();
