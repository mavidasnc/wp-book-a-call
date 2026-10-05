import { createRoot, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { TabPanel } from '@wordpress/components';
import Bookings from './pages/Bookings';
import EventTypes from './pages/EventTypes';
import Exceptions from './pages/Exceptions';
import Settings from './pages/Settings';
import Updates from './pages/Updates';
import './styles/admin.scss';

const TABS = [
	{ name: 'bookings', title: __( 'Prenotazioni', 'wp-book-a-call' ) },
	{ name: 'types', title: __( 'Tipi di call', 'wp-book-a-call' ) },
	{ name: 'exceptions', title: __( 'Eccezioni', 'wp-book-a-call' ) },
	{ name: 'settings', title: __( 'Impostazioni', 'wp-book-a-call' ) },
	{ name: 'updates', title: __( 'Aggiornamenti', 'wp-book-a-call' ) },
];

function App() {
	// Scheda iniziale: quella indicata da ?tab=... (es. il link nell'elenco plugin) oppure Impostazioni
	// dopo il ritorno da Google.
	const [ initial ] = useState( () => {
		const params = new URLSearchParams( window.location.search );
		const requested = params.get( 'tab' );
		if ( TABS.some( ( tab ) => tab.name === requested ) ) {
			return requested;
		}
		return params.has( 'wpbac_google' ) ? 'settings' : 'bookings';
	} );

	return (
		<div className="wpbac-admin">
			<header className="wpbac-admin__header">
				<h1>{ __( 'Book a call', 'wp-book-a-call' ) }</h1>
				<p>{ __( 'Gestisci prenotazioni, disponibilità e integrazione con Google Calendar.', 'wp-book-a-call' ) }</p>
			</header>
			<TabPanel tabs={ TABS } initialTabName={ initial }>
				{ ( tab ) => {
					switch ( tab.name ) {
						case 'types':
							return <EventTypes />;
						case 'exceptions':
							return <Exceptions />;
						case 'settings':
							return <Settings />;
						case 'updates':
							return <Updates />;
						default:
							return <Bookings />;
					}
				} }
			</TabPanel>
		</div>
	);
}

const container = document.getElementById( 'wpbac-admin-root' );
if ( container ) {
	createRoot( container ).render( <App /> );
}
