/**
 * Script di release per WP Book a Call.
 *
 * Uso:
 *   npm run release:patch | release:minor | release:major
 *   node scripts/release.js [patch|minor|major|x.y.z] [--local] [--yes]
 *
 * Di default:
 *   1. verifica working tree pulito e gh autenticato;
 *   2. aggiorna la versione (package.json, header del plugin, WPBAC_VERSION, phpstan-constants.php);
 *   3. crea commit e tag vX.Y.Z e fa push;
 *   4. il workflow GitHub Actions (.github/workflows/release.yml) costruisce lo zip e pubblica la release:
 *      lo script attende che la release compaia.
 *
 * Con --local lo zip si costruisce in locale e la release si crea con gh (utile se Actions non è disponibile).
 * Con --yes non chiede conferma.
 *
 * Requisiti: git, gh CLI autenticata; con --local anche Node, npm e Composer.
 */

const { execSync } = require( 'child_process' );
const fs = require( 'fs' );
const path = require( 'path' );
const readline = require( 'readline' );

const ROOT = path.resolve( __dirname, '..' );
const PKG_PATH = path.join( ROOT, 'package.json' );
const PLUGIN_PHP = path.join( ROOT, 'wp-book-a-call.php' );
const STAN_CONSTS = path.join( ROOT, 'phpstan-constants.php' );
const CHANGELOG = path.join( ROOT, 'CHANGELOG.md' );
const SLUG = 'wp-book-a-call';
const REPO = 'mavidasnc/wp-book-a-call';

const args = process.argv.slice( 2 );
const LOCAL = args.includes( '--local' );
const ASSUME_YES = args.includes( '--yes' );
const bumpType = args.find( ( a ) => ! a.startsWith( '--' ) ) || 'patch';

// ── Helpers ──────────────────────────────────────────────────────────────────

function run( cmd, { silent = false } = {} ) {
	if ( ! silent ) {
		process.stdout.write( `\n\x1b[36m$ ${ cmd }\x1b[0m\n` );
	}
	return execSync( cmd, { cwd: ROOT, stdio: silent ? 'pipe' : 'inherit', encoding: 'utf8' } );
}

const ok = ( msg ) => console.log( `\x1b[32m✓\x1b[0m ${ msg }` );
const warn = ( msg ) => console.log( `\x1b[33m⚠\x1b[0m ${ msg }` );
const step = ( msg ) => console.log( `\n\x1b[1m${ msg }\x1b[0m` );
const fail = ( msg ) => {
	console.error( `\x1b[31m✗\x1b[0m ${ msg }` );
	process.exit( 1 );
};
const sleep = ( ms ) => new Promise( ( resolve ) => setTimeout( resolve, ms ) );

function bumpVersion( current, type ) {
	// Versione esplicita (es. "1.2.3").
	if ( /^\d+\.\d+\.\d+$/.test( type ) ) {
		return type;
	}
	const [ maj, min, pat ] = current.split( '.' ).map( Number );
	if ( type === 'major' ) {
		return `${ maj + 1 }.0.0`;
	}
	if ( type === 'minor' ) {
		return `${ maj }.${ min + 1 }.0`;
	}
	return `${ maj }.${ min }.${ pat + 1 }`;
}

function ask( question ) {
	if ( ASSUME_YES ) {
		return Promise.resolve( true );
	}
	return new Promise( ( resolve ) => {
		const rl = readline.createInterface( { input: process.stdin, output: process.stdout } );
		rl.question( `${ question } [y/N] `, ( answer ) => {
			rl.close();
			resolve( answer.trim().toLowerCase() === 'y' );
		} );
	} );
}

/** Sostituisce la versione in un file con una regex (due gruppi: prefisso e suffisso opzionale). */
function replaceInFile( file, regex, version ) {
	const content = fs.readFileSync( file, 'utf8' );
	fs.writeFileSync( file, content.replace( regex, `$1${ version }$2` ) );
}

/** Attende che la release esista su GitHub con lo zip allegato (la crea il workflow). */
async function waitForRelease( tag, zipName ) {
	const deadline = Date.now() + 8 * 60 * 1000;
	while ( Date.now() < deadline ) {
		try {
			const out = run( `gh release view ${ tag } --json assets --jq ".assets[].name"`, { silent: true } );
			if ( out.split( '\n' ).includes( zipName ) ) {
				return true;
			}
		} catch {
			// La release non esiste ancora: si riprova.
		}
		process.stdout.write( '.' );
		await sleep( 10000 );
	}
	return false;
}

// ── Main ─────────────────────────────────────────────────────────────────────

async function main() {
	// ── 1. Validazione ───────────────────────────────────────────────────────
	step( '🔍 Validazione pre-release' );

	if ( run( 'git status --porcelain', { silent: true } ).trim() ) {
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
	const pkg = JSON.parse( fs.readFileSync( PKG_PATH, 'utf8' ) );
	const oldVersion = pkg.version;
	const newVersion = bumpVersion( oldVersion, bumpType );
	const tag = `v${ newVersion }`;

	if ( oldVersion === newVersion ) {
		fail( `Versione invariata: ${ oldVersion }. Usa "patch", "minor", "major" o una versione esplicita.` );
	}
	if ( ! fs.readFileSync( CHANGELOG, 'utf8' ).includes( `[${ newVersion }]` ) ) {
		warn( `CHANGELOG.md non ha ancora la sezione [${ newVersion }]. Aggiornalo prima di fare release.` );
	}

	console.log( `\n  ${ oldVersion }  →  \x1b[1m\x1b[32m${ newVersion }\x1b[0m${ LOCAL ? '  (build locale)' : '' }` );
	if ( ! ( await ask( '\nConfermi la release?' ) ) ) {
		fail( 'Release annullata.' );
	}

	// ── 3. Bump versioni nei file ─────────────────────────────────────────────
	step( '✏️  Aggiornamento versioni' );

	pkg.version = newVersion;
	fs.writeFileSync( PKG_PATH, JSON.stringify( pkg, null, 2 ) + '\n' );
	ok( 'package.json' );

	replaceInFile( PLUGIN_PHP, /(\s\*\s+Version:\s+)\d+\.\d+\.\d+()/, newVersion );
	replaceInFile( PLUGIN_PHP, /(define\(\s*'WPBAC_VERSION',\s*')[\d.]+('\s*\))/, newVersion );
	ok( 'wp-book-a-call.php' );

	replaceInFile( STAN_CONSTS, /(define\(\s*'WPBAC_VERSION',\s*')[\d.]+('\s*\))/, newVersion );
	ok( 'phpstan-constants.php' );

	// ── 4. Build locale (solo con --local) ────────────────────────────────────
	const zipRel = `zip/${ SLUG }-${ newVersion }.zip`;
	if ( LOCAL ) {
		step( '🏗  Build e zip locali' );
		run( 'npm run build' );
		run( 'composer install --no-dev --optimize-autoloader --no-interaction' );
		run( 'node scripts/build-zip.js' );
		run( 'composer install --optimize-autoloader --no-interaction' );
		if ( ! fs.existsSync( path.join( ROOT, zipRel ) ) ) {
			fail( `ZIP non trovato: ${ zipRel }` );
		}
		ok( zipRel );
	}

	// ── 5. Git commit + tag + push ────────────────────────────────────────────
	step( '📝 Git commit, tag e push' );
	run( `git add "${ PKG_PATH }" "${ PLUGIN_PHP }" "${ STAN_CONSTS }"` );
	run( `git commit -m "chore: release ${ tag }"` );
	run( `git tag ${ tag }` );
	run( 'git push' );
	run( `git push origin ${ tag }` );
	ok( `push completato (${ tag })` );

	// ── 6. Release ────────────────────────────────────────────────────────────
	step( '🚀 GitHub Release' );
	if ( LOCAL ) {
		const notes = `Vedi [CHANGELOG.md](https://github.com/${ REPO }/blob/main/CHANGELOG.md) per i dettagli delle modifiche.`;
		run( `gh release create "${ tag }" "${ zipRel }" --title "${ tag }" --notes "${ notes }"` );
	} else {
		console.log( `Il workflow sta costruendo lo zip: https://github.com/${ REPO }/actions\nAttendo la release` );
		if ( ! ( await waitForRelease( tag, `${ SLUG }-${ newVersion }.zip` ) ) ) {
			fail( `La release ${ tag } non è comparsa entro 8 minuti. Controlla il workflow su GitHub Actions, oppure rilancia con --local.` );
		}
		console.log();
	}
	ok( `Release ${ tag } pubblicata` );

	console.log( `\n\x1b[1m\x1b[32m✅ Release ${ tag } completata!\x1b[0m` );
	console.log( `   https://github.com/${ REPO }/releases/tag/${ tag }\n` );
}

main().catch( ( err ) => {
	console.error( '\n\x1b[31m✗ Errore durante la release:\x1b[0m', err.message || err );
	process.exit( 1 );
} );
