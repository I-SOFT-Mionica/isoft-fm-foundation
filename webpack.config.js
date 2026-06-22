const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path          = require( 'path' );

module.exports = {
	...defaultConfig,
	entry: {
		'download-list':   './blocks/download-list/index.js',
		'download-button': './blocks/download-button/index.js',
		'category-grid':   './blocks/category-grid/index.js',
		'editor-sidebar':  './blocks/editor-sidebar/index.js',
		'licenses-page':   './blocks/licenses-page/index.js',
		'stats-page':      './blocks/stats-page/index.js',
	},
	output: {
		...defaultConfig.output,
		filename: '[name].js',
		path: path.resolve( process.cwd(), 'blocks/build' ),
	},
};
