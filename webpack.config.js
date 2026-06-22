const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const DependencyExtractionWebpackPlugin = require( '@wordpress/dependency-extraction-webpack-plugin' );
const path = require( 'path' );

// Replace the default DependencyExtractionWebpackPlugin with one that
// also recognises @wordpress/dataviews → wp-dataviews. Without this,
// dataviews is bundled into our output (adds ~220 KB per entry that
// uses it) instead of being loaded from WP core as a separate script
// handle. The plugin's default requestToExternal map is shipped from
// @wordpress/scripts, so we extend rather than replace it.
const plugins = ( defaultConfig.plugins || [] ).filter(
	( p ) => ! ( p instanceof DependencyExtractionWebpackPlugin )
);
plugins.push(
	new DependencyExtractionWebpackPlugin( {
		requestToExternal( request ) {
			if ( '@wordpress/dataviews' === request ) {
				return [ 'wp', 'dataviews' ];
			}
		},
		requestToHandle( request ) {
			if ( '@wordpress/dataviews' === request ) {
				return 'wp-dataviews';
			}
		},
	} )
);

module.exports = {
	...defaultConfig,
	entry: {
		'download-list':   './blocks/download-list/index.js',
		'download-button': './blocks/download-button/index.js',
		'category-grid':   './blocks/category-grid/index.js',
		'editor-sidebar':  './blocks/editor-sidebar/index.js',
		'licenses-page':   './blocks/licenses-page/index.js',
		'stats-page':      './blocks/stats-page/index.js',
		'log-page':        './blocks/log-page/index.js',
	},
	output: {
		...defaultConfig.output,
		filename: '[name].js',
		path: path.resolve( process.cwd(), 'blocks/build' ),
	},
	plugins,
};
