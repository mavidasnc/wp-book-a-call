import { createPortal, useEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

const FOCUSABLE =
	'a[href],button:not([disabled]),input:not([disabled]):not([tabindex="-1"]),select:not([disabled]),textarea:not([disabled])';

/**
 * Modale accessibile e leggera (senza @wordpress/components, per non caricare il CSS admin nel frontend).
 * Chiusura con Esc o clic sul fondo, focus intrappolato, scroll bloccato, focus ripristinato alla chiusura.
 */
export default function Modal( { title, onClose, children } ) {
	const panel = useRef();
	const closeRef = useRef( onClose );
	closeRef.current = onClose;

	useEffect( () => {
		const opener = document.activeElement;
		document.body.classList.add( 'wpbac-modal-open' );

		// Il focus va al primo campo, altrimenti al pannello.
		const first = panel.current.querySelector( 'input:not([tabindex="-1"]),textarea,select' );
		( first ?? panel.current ).focus();

		const onKey = ( e ) => {
			if ( 'Escape' === e.key ) {
				e.stopPropagation();
				closeRef.current();
				return;
			}
			if ( 'Tab' !== e.key ) {
				return;
			}
			// Trap del focus dentro la modale.
			const items = Array.from( panel.current.querySelectorAll( FOCUSABLE ) );
			if ( ! items.length ) {
				return;
			}
			const firstItem = items[ 0 ];
			const lastItem = items[ items.length - 1 ];
			if ( e.shiftKey && document.activeElement === firstItem ) {
				e.preventDefault();
				lastItem.focus();
			} else if ( ! e.shiftKey && document.activeElement === lastItem ) {
				e.preventDefault();
				firstItem.focus();
			}
		};
		document.addEventListener( 'keydown', onKey );

		return () => {
			document.removeEventListener( 'keydown', onKey );
			document.body.classList.remove( 'wpbac-modal-open' );
			if ( opener && opener.focus ) {
				opener.focus();
			}
		};
	}, [] );

	return createPortal(
		<div
			className="wpbac-modal"
			onMouseDown={ ( e ) => {
				if ( e.target === e.currentTarget ) {
					onClose();
				}
			} }
		>
			<div
				className="wpbac-modal__panel"
				role="dialog"
				aria-modal="true"
				aria-labelledby="wpbac-modal-title"
				tabIndex="-1"
				ref={ panel }
			>
				<button
					type="button"
					className="wpbac-modal__close"
					onClick={ onClose }
					aria-label={ __( 'Chiudi', 'wp-book-a-call' ) }
				>
					×
				</button>
				<h3 id="wpbac-modal-title" className="wpbac-modal__title">
					{ title }
				</h3>
				{ children }
			</div>
		</div>,
		document.body
	);
}
