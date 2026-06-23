const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path          = require( 'path' );

// Note: @wordpress/dataviews is NOT externalised. WP core registers the
// `wp-dataviews` script handle only inside specific editor contexts
// (post / site editor) — outside those screens an enqueue depending on
// it triggers the WP 6.9.1+ "dependencies that are not registered"
// notice. Bundling adds ~220 KB to entries that use it (currently only
// log-page; later broken-links-page) but ships a working admin screen
// instead of a half-broken one. Revisit if WP core promotes
// wp-dataviews to a globally-registered handle.

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
		'broken-links-page': './blocks/broken-links-page/index.js',
		'settings-page':   './blocks/settings-page/index.js',
		'profile-acl':     './blocks/profile-acl/index.js',
		'taxonomy-form':   './blocks/taxonomy-form/index.js',
	},
	output: {
		...defaultConfig.output,
		filename: '[name].js',
		path: path.resolve( process.cwd(), 'blocks/build' ),
	},
};
