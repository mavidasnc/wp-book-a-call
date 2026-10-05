import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Button, ExternalLink, Notice, Spinner } from '@wordpress/components';
import { api } from '../api';
import Actions from '../components/Actions';
import Section from '../components/Section';

/** Data dell'ultimo controllo, oppure "mai". */
const checkedAt = ( ts ) =>
	ts
		? new Date( ts * 1000 ).toLocaleString( 'it-IT', { dateStyle: 'medium', timeStyle: 'short' } )
		: __( 'mai', 'wp-book-a-call' );

export default function Updates() {
	const [ status, setStatus ] = useState( null );
	const [ busy, setBusy ] = useState( '' ); // 'check' | 'install'
	const [ notice, setNotice ] = useState( null );

	useEffect( () => {
		api( '/admin/update' )
			.then( setStatus )
			.catch( ( e ) => setNotice( { status: 'error', text: e.message } ) );
	}, [] );

	const check = () => {
		setBusy( 'check' );
		setNotice( null );
		api( '/admin/update/check', { method: 'POST' } )
			.then( ( data ) => {
				setStatus( data );
				setNotice(
					data.update_available
						? { status: 'warning', text: sprintf( __( 'È disponibile la versione %s.', 'wp-book-a-call' ), data.latest ) }
						: { status: 'success', text: __( 'Il plugin è aggiornato all\'ultima versione.', 'wp-book-a-call' ) }
				);
			} )
			.catch( ( e ) => setNotice( { status: 'error', text: e.message } ) )
			.finally( () => setBusy( '' ) );
	};

	const install = () => {
		setBusy( 'install' );
		setNotice( null );
		api( '/admin/update/install', { method: 'POST' } )
			.then( ( data ) => {
				setNotice( { status: 'success', text: sprintf( __( 'Aggiornato alla versione %s. Ricarico la pagina…', 'wp-book-a-call' ), data.version ) } );
				// Gli script dell'admin sono cambiati: serve ricaricare per usare la versione nuova.
				setTimeout( () => window.location.reload(), 2000 );
			} )
			.catch( ( e ) => {
				setNotice( { status: 'error', text: e.message } );
				setBusy( '' );
			} );
	};

	if ( ! status && ! notice ) {
		return <Spinner />;
	}

	return (
		<div className="wpbac-admin__panel">
			<Section
				title={ __( 'Aggiornamenti', 'wp-book-a-call' ) }
				description={ __( 'Il plugin si aggiorna dalle release pubblicate su GitHub. WordPress controlla da solo ogni 12 ore: qui puoi farlo subito.', 'wp-book-a-call' ) }
			>
				{ notice && (
					<Notice status={ notice.status } onRemove={ () => setNotice( null ) }>
						{ notice.text }
					</Notice>
				) }

				{ status && (
					<>
						<dl className="wpbac-admin__facts">
							<div>
								<dt>{ __( 'Versione installata', 'wp-book-a-call' ) }</dt>
								<dd>{ status.current }</dd>
							</div>
							<div>
								<dt>{ __( 'Ultima versione', 'wp-book-a-call' ) }</dt>
								<dd>
									{ status.latest }
									{ status.update_available && <span className="wpbac-admin__badge is-confirmed">{ __( 'Nuova', 'wp-book-a-call' ) }</span> }
								</dd>
							</div>
							<div>
								<dt>{ __( 'Ultimo controllo', 'wp-book-a-call' ) }</dt>
								<dd>{ checkedAt( status.checked_at ) }</dd>
							</div>
						</dl>

						<Actions>
							<Button variant="secondary" isBusy={ 'check' === busy } disabled={ !! busy } onClick={ check }>
								{ 'check' === busy ? __( 'Controllo in corso…', 'wp-book-a-call' ) : __( 'Verifica aggiornamenti', 'wp-book-a-call' ) }
							</Button>
							{ status.update_available && (
								<Button variant="primary" isBusy={ 'install' === busy } disabled={ !! busy } onClick={ install }>
									{ 'install' === busy
										? __( 'Installazione in corso…', 'wp-book-a-call' )
										: sprintf( __( 'Installa la versione %s', 'wp-book-a-call' ), status.latest ) }
								</Button>
							) }
						</Actions>

						<p className="wpbac-admin__hint">
							<ExternalLink href={ status.releases_url }>{ __( 'Note di versione su GitHub', 'wp-book-a-call' ) }</ExternalLink>
						</p>
					</>
				) }
			</Section>
		</div>
	);
}
