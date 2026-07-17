const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path          = require( 'path' );

// Note: @wordpress/dataviews is NOT externalised. WP core registers the
// `wp-dataviews` script handle only inside specific editor contexts
// (post / site editor) — outside those screens an enqueue depending on
// it triggers the WP 6.9.1+ "dependencies that are not registered"
// notice. Bundling adds ~220 KB to the admin-shell entry (the only
// entry that imports it as of 0.12.6), but ships a working admin
// screen instead of a half-broken one. Revisit if WP core promotes
// wp-dataviews to a globally-registered handle.

module.exports = {
	...defaultConfig,
	entry: {
		'download-list':   './blocks/download-list/index.js',
		'download-button': './blocks/download-button/index.js',
		'category-grid':   './blocks/category-grid/index.js',
		'editor-sidebar':  './blocks/editor-sidebar/index.js',
		'profile-acl':     './blocks/profile-acl/index.js',
		'taxonomy-form':   './blocks/taxonomy-form/index.js',
		// One admin-shell entry replaces the five per-page bundles
		// (licenses-page, stats-page, log-page, broken-links-page,
		// settings-page) that shipped through 0.12.5. Every admin
		// screen enqueues this one bundle; client-side nav swaps
		// sections without a page reload. See class-admin-shell.php.
		'admin-shell':     './blocks/admin-shell/index.js',
	},
	output: {
		...defaultConfig.output,
		filename: '[name].js',
		path:     path.resolve( process.cwd(), 'blocks/build' ),
	},
	// splitChunks placeholder — kept in preparation for future entries
	// (Sentinel / Orbit addons) that will share DataViews + components.
	// With only admin-shell needing DataViews today, splitChunks
	// wouldn't emit a shared chunk (single-consumer heuristic); leaving
	// defaultConfig.optimization untouched.
};
