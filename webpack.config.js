const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

// Aggiunge l'entry dell'admin React a quelle dei blocchi (auto-rilevate da block.json).
module.exports = {
	...defaultConfig,
	entry: async () => ( {
		...( await defaultConfig.entry() ),
		'admin/index': './assets/admin/index.jsx',
	} ),
};
