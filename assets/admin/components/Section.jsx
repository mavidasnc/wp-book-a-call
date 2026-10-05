/**
 * Card bianca con titolo e testo di aiuto: unità base del layout dell'admin.
 */
export default function Section( { title, description, children } ) {
	return (
		<section className="wpbac-admin__card">
			{ title && <h2 className="wpbac-admin__card-title">{ title }</h2> }
			{ description && <p className="wpbac-admin__card-desc">{ description }</p> }
			{ children }
		</section>
	);
}
