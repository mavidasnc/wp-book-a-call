/**
 * Script di release automatizzato per WP Book a Call.
 *
 * Uso:
 *   node scripts/release.js [patch|minor|major|x.y.z]
 *   npm run release:patch   (default)
 *   npm run release:minor
 *   npm run release:major
 *
 * Il script esegue in sequenza:
 *   1. Valida working tree pulito e gh CLI autenticata
 *   2. Chiede conferma della versione target
 *   3. Aggiorna la versione in package.json e wp-book-a-call.php
 *   4. Build JS/CSS
 *   5. composer install --no-dev  (vendor pulito per distribuzione)
 *   6. Crea il ZIP distribuibile
 *   7. Ripristina composer con devDependencies
 *   8. git commit + tag + push
 *   9. gh release create con il ZIP allegato
 *
 * Requisiti: git, gh CLI (github.com/cli/cli), Node ≥ 18, Composer
 */

const { execSync }  = require( 'child_process' );
const fs            = require( 'fs' );
const path          = require( 'path' );
const readline      = require( 'readline' );

const ROOT        = path.resolve( __dirname, '..' );
const PKG_PATH    = path.join( ROOT, 'package.json' );
const PLUGIN_PHP  = path.join( ROOT, 'wp-book-a-call.php' );
const CHANGELOG   = path.join( ROOT, 'CHANGELOG.md' );
const STAN_CONSTS = path.join( ROOT, 'phpstan-constants.php' );
const SLUG        = 'wp-book-a-call';

// ── Helpers ──────────────────────────────────────────────────────────────────

function run( cmd, { silent = false } = {} ) {
	if ( ! silent ) process.stdout.write( `\n\x1b[36m$ ${ cmd }\x1b[0m\n` );
	return execSync( cmd, { cwd: ROOT, stdio: silent ? 'pipe' : 'inherit', encoding: 'utf8' } );
}

function ok( msg )   { console.log( `\x1b[32m✓\x1b[0m ${ msg }` ); }
function fail( msg ) { console.error( `\x1b[31m✗\x1b[0m ${ msg }` ); process.exit( 1 ); }
function warn( msg ) { console.log( `\x1b[33m⚠\x1b[0m ${ msg }` ); }
function step( msg ) { console.log( `\n\x1b[1m${ msg }\x1b[0m` ); }

function bumpVersion( current, type ) {
	// Versione esplicita (es. "1.2.3").
	if ( /^\d+\.\d+\.\d+$/.test( type ) ) return type;
	const [ maj, min, pat ] = current.split( '.' ).map( Number );
	if ( type === 'major' ) return `${ maj + 1 }.0.0`;
	if ( type === 'minor' ) return `${ maj }.${ min + 1 }.0`;
	return `${ maj }.${ min }.${ pat + 1 }`;
}

function ask( question ) {
	return new Promise( ( resolve ) => {
		const rl = readline.createInterface( { input: process.stdin, output: process.stdout } );
		rl.question( `${ question } [y/N] `, ( answer ) => {
			rl.close();
			resolve( answer.trim().toLowerCase() === 'y' );
		} );
	} );
}

// ── Main ─────────────────────────────────────────────────────────────────────

async function main() {
	const bumpType = process.argv[ 2 ] || 'patch';

	// ── 1. Validazione ───────────────────────────────────────────────────────
	step( '🔍 Validazione pre-release' );

	const gitStatus = run( 'git status --porcelain', { silent: true } );
	if ( gitStatus.trim() ) {
		fail( 'Ci sono modifiche non committate. Esegui "git status" e committa prima di fare release.' );
	}
	ok( 'Working tree pulito' );

	try {
		run( 'gh auth status', { silent: true } );
		ok( 'GitHub CLI autenticata' );
	} catch {
		fail( 'GitHub CLI non autenticata. Esegui: gh auth login' );
	}

	// ── 2. Calcolo nuova versione ─────────────────────────────────────────────
	const pkg        = JSON.parse( fs.readFileSync( PKG_PATH, 'utf8' ) );
	const oldVersion = pkg.version;
	const newVersion = bumpVersion( oldVersion, bumpType );

	if ( oldVersion === newVersion ) {
		fail( `Versione invariata: ${ oldVersion }. Usa "patch", "minor", "major" o una versione esplicita.` );
	}

	const changelog = fs.readFileSync( CHANGELOG, 'utf8' );
	if ( ! changelog.includes( `[${ newVersion }]` ) ) {
		warn( `CHANGELOG.md non ha ancora la sezione [${ newVersion }]. Aggiornalo prima di fare release.` );
	}

	console.log( `\n  ${ oldVersion }  →  \x1b[1m\x1b[32m${ newVersion }\x1b[0m` );
	const confirmed = await ask( '\nConfermi la release?' );
	if ( ! confirmed ) fail( 'Release annullata.' );

	// ── 3. Bump versioni nei file ─────────────────────────────────────────────
	step( '✏️  Aggiornamento versioni' );

	pkg.version = newVersion;
	fs.writeFileSync( PKG_PATH, JSON.stringify( pkg, null, 2 ) + '\n' );
	ok( 'package.json' );

	let pluginPhp = fs.readFileSync( PLUGIN_PHP, 'utf8' );
	pluginPhp = pluginPhp
		.replace( /(\s\*\s+Version:\s+)\d+\.\d+\.\d+/, `$1${ newVersion }` )
		.replace( /(define\(\s*'WPBAC_VERSION',\s*')[\d.]+('\s*\))/, `$1${ newVersion }$2` );
	fs.writeFileSync( PLUGIN_PHP, pluginPhp );
	ok( 'wp-book-a-call.php' );

	let stanConsts = fs.readFileSync( STAN_CONSTS, 'utf8' );
	stanConsts = stanConsts.replace( /(define\(\s*'WPBAC_VERSION',\s*')[\d.]+('\s*\))/, `$1${ newVersion }$2` );
	fs.writeFileSync( STAN_CONSTS, stanConsts );
	ok( 'phpstan-constants.php' );

	// ── 4. Build JS/CSS ───────────────────────────────────────────────────────
	step( '🏗  Build assets' );
	run( 'npm run build' );
	ok( 'Build completata' );

	// ── 5. Composer senza devDependencies ────────────────────────────────────
	step( '📦 Composer install --no-dev' );
	run( 'composer install --no-dev --optimize-autoloader --no-interaction' );
	ok( 'Vendor pulito (solo produzione)' );

	// ── 6. ZIP ───────────────────────────────────────────────────────────────
	step( '🗜  Creazione ZIP' );
	run( 'node scripts/build-zip.js' );
	const zipFile = path.join( ROOT, 'zip', `${ SLUG }-${ newVersion }.zip` );
	if ( ! fs.existsSync( zipFile ) ) fail( `ZIP non trovato: ${ zipFile }` );
	ok( `zip/${ SLUG }-${ newVersion }.zip` );

	// ── 7. Ripristino devDependencies ────────────────────────────────────────
	step( '🔄 Ripristino Composer con devDependencies' );
	run( 'composer install --optimize-autoloader --no-interaction' );
	ok( 'DevDependencies ripristinate' );

	// ── 8. Git commit + tag + push ────────────────────────────────────────────
	step( '📝 Git commit, tag e push' );
	run( `git add "${ PKG_PATH }" "${ PLUGIN_PHP }" "${ STAN_CONSTS }"` );
	run( `git commit -m "chore: release v${ newVersion }"` );
	ok( `commit: chore: release v${ newVersion }` );
	run( `git tag v${ newVersion }` );
	ok( `tag: v${ newVersion }` );
	run( 'git push' );
	run( 'git push --tags' );
	ok( 'push completato' );

	// ── 9. GitHub Release ─────────────────────────────────────────────────────
	step( '🚀 GitHub Release' );
	const notes = `Vedi [CHANGELOG.md](https://github.com/mavidasnc/wp-book-a-call/blob/main/CHANGELOG.md) per i dettagli delle modifiche.`;
	run( `gh release create "v${ newVersion }" "${ zipFile }" --title "v${ newVersion }" --notes "${ notes }"` );
	ok( `Release v${ newVersion } pubblicata` );

	console.log( `\n\x1b[1m\x1b[32m✅ Release v${ newVersion } completata!\x1b[0m` );
	console.log( `   https://github.com/mavidasnc/wp-book-a-call/releases/tag/v${ newVersion }\n` );
}

main().catch( ( err ) => {
	console.error( '\n\x1b[31m✗ Errore durante la release:\x1b[0m', err.message || err );
	process.exit( 1 );
} );
