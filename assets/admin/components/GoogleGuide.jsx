import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, ExternalLink } from '@wordpress/components';

const CONSOLE = 'https://console.cloud.google.com';

/**
 * Guida passo-passo per ottenere Client ID e Client secret nella Google Cloud Console.
 * Contiene l'URI di reindirizzamento da copiare, che dipende dal sito.
 */
export default function GoogleGuide( { redirectUri } ) {
	const [ copied, setCopied ] = useState( false );

	const copy = () => {
		if ( ! navigator.clipboard ) {
			return;
		}
		navigator.clipboard.writeText( redirectUri ).then( () => {
			setCopied( true );
			setTimeout( () => setCopied( false ), 2000 );
		} );
	};

	return (
		<details className="wpbac-admin__guide">
			<summary>{ __( 'Come ottenere Client ID e Client secret (guida passo-passo)', 'wp-book-a-call' ) }</summary>
			<ol className="wpbac-admin__steps">
				<li>
					<strong>{ __( 'Crea un progetto.', 'wp-book-a-call' ) }</strong>{ ' ' }
					{ __( 'Vai su Google Cloud Console e crea un nuovo progetto (ad esempio "Prenotazioni sito").', 'wp-book-a-call' ) }{ ' ' }
					<ExternalLink href={ `${ CONSOLE }/projectcreate` }>{ __( 'Crea progetto', 'wp-book-a-call' ) }</ExternalLink>
				</li>
				<li>
					<strong>{ __( 'Abilita la Google Calendar API.', 'wp-book-a-call' ) }</strong>{ ' ' }
					{ __( 'Nel progetto appena creato premi "Abilita".', 'wp-book-a-call' ) }{ ' ' }
					<ExternalLink href={ `${ CONSOLE }/apis/library/calendar-json.googleapis.com` }>{ __( 'Apri la libreria API', 'wp-book-a-call' ) }</ExternalLink>
				</li>
				<li>
					<strong>{ __( 'Configura la schermata di consenso.', 'wp-book-a-call' ) }</strong>{ ' ' }
					{ __( 'Scegli il tipo "Esterno", inserisci nome app ed email, aggiungi il tuo account Google tra gli utenti di test. Poi premi "Pubblica app" (stato "In produzione"): in modalità test il collegamento scade dopo 7 giorni. Per uso personale non serve la verifica di Google; al primo accesso vedrai un avviso "app non verificata": scegli "Avanzate" e poi "Vai a ...".', 'wp-book-a-call' ) }{ ' ' }
					<ExternalLink href={ `${ CONSOLE }/apis/credentials/consent` }>{ __( 'Schermata di consenso', 'wp-book-a-call' ) }</ExternalLink>
				</li>
				<li>
					<strong>{ __( 'Crea le credenziali.', 'wp-book-a-call' ) }</strong>{ ' ' }
					{ __( 'Credenziali → Crea credenziali → ID client OAuth → tipo "Applicazione web" (non "Desktop"). In "URI di reindirizzamento autorizzati" incolla questo indirizzo:', 'wp-book-a-call' ) }{ ' ' }
					<ExternalLink href={ `${ CONSOLE }/apis/credentials` }>{ __( 'Apri le credenziali', 'wp-book-a-call' ) }</ExternalLink>
					<span className="wpbac-admin__copy">
						<code>{ redirectUri }</code>
						<Button variant="secondary" size="compact" onClick={ copy }>
							{ copied ? __( 'Copiato', 'wp-book-a-call' ) : __( 'Copia', 'wp-book-a-call' ) }
						</Button>
					</span>
				</li>
				<li>
					<strong>{ __( 'Copia le chiavi.', 'wp-book-a-call' ) }</strong>{ ' ' }
					{ __( 'Google mostra Client ID e Client secret: incollali nei campi qui sotto e premi "Salva e collega con Google". Accetta i permessi richiesti e tornerai su questa pagina con lo stato "Collegato".', 'wp-book-a-call' ) }
				</li>
			</ol>
		</details>
	);
}
