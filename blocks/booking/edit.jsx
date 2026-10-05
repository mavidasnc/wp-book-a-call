import { __ } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, SelectControl, ToggleControl } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

export default function Edit( { attributes, setAttributes } ) {
	const { eventTypeSlug, showHostInfo, showTimezoneSelector } = attributes;
	const [ types, setTypes ] = useState( [] );

	// Elenco dei tipi di call (endpoint riservato agli admin: l'editor è autenticato).
	useEffect( () => {
		apiFetch( { path: '/wpbac/v1/admin/event-types' } )
			.then( setTypes )
			.catch( () => setTypes( [] ) );
	}, [] );

	const current = types.find( ( t ) => t.slug === eventTypeSlug ) ?? types[ 0 ];

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Prenotazione', 'wp-book-a-call' ) }>
					<SelectControl
						label={ __( 'Tipo di call', 'wp-book-a-call' ) }
						help={ __( 'Se non scelto, si usa il primo tipo di call attivo.', 'wp-book-a-call' ) }
						value={ eventTypeSlug }
						options={ [
							{ value: '', label: __( '(primo attivo)', 'wp-book-a-call' ) },
							...types.map( ( t ) => ( { value: t.slug, label: t.title } ) ),
						] }
						onChange={ ( v ) => setAttributes( { eventTypeSlug: v } ) }
					/>
					<ToggleControl
						label={ __( 'Mostra il nome dell\'organizzatore', 'wp-book-a-call' ) }
						checked={ showHostInfo }
						onChange={ ( v ) => setAttributes( { showHostInfo: v } ) }
					/>
					<ToggleControl
						label={ __( 'Mostra il selettore del fuso orario', 'wp-book-a-call' ) }
						checked={ showTimezoneSelector }
						onChange={ ( v ) => setAttributes( { showTimezoneSelector: v } ) }
					/>
				</PanelBody>
			</InspectorControls>
			<div { ...useBlockProps() }>
				<strong>{ __( 'Book a Call', 'wp-book-a-call' ) }</strong>
				<p>
					{ current
						? `${ current.title } · ${ current.duration_min } ${ __( 'minuti', 'wp-book-a-call' ) }`
						: __( 'Nessun tipo di call: creane uno da Book a Call > Tipi di call.', 'wp-book-a-call' ) }
				</p>
				<p>{ __( 'Il calendario di prenotazione apparirà qui nella pagina pubblicata.', 'wp-book-a-call' ) }</p>
			</div>
		</>
	);
}
