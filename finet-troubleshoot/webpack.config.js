const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
	...defaultConfig,
	entry: {
		admin: path.resolve( process.cwd(), 'assets/src/admin/index.js' ),
		frontend: path.resolve( process.cwd(), 'assets/src/frontend/index.js' ),
		outages: path.resolve( process.cwd(), 'assets/src/outages/index.js' ),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( process.cwd(), 'assets/build' ),
		filename: '[name].js',
	},
};
