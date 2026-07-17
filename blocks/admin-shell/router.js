/**
 * Client-side router for the admin shell.
 *
 * 0.12.9 scope reduction: routing is intra-page only. Each top
 * section (Licenses / Tools / Settings) is its own admin page — the
 * router owns sub-tab nav within the currently-active top (Tools'
 * Stats/Log/BrokenLinks strip, Settings' vertical sidebar). Clicking
 * a WP admin sidebar link to a different top page goes through the
 * WP admin's normal full navigation.
 *
 * Sub-tab URL mapping:
 *   - Licenses has no sub-tabs.
 *   - Tools sub → page slug: stats→isoft-fmf-stats, log→isoft-fmf-log,
 *     broken-links→isoft-fmf-broken-links. (?page=isoft-fmf-tools also
 *     resolves and lands on stats by default.)
 *   - Settings sub → ?tab= param on ?page=isoft-fmf-settings.
 */

/** Valid sub-tabs per top section. */
export const SUBS_BY_TOP = {
	licenses: [],
	tools:    [ 'stats', 'log', 'broken-links' ],
	settings: [ 'general', 'display', 'security', 'advanced', 'maintenance', 'extensions' ],
};

const TOOLS_URL_TO_SUB = {
	'isoft-fmf-tools':        'stats',
	'isoft-fmf-stats':        'stats',
	'isoft-fmf-log':          'log',
	'isoft-fmf-broken-links': 'broken-links',
};

/**
 * Parse a URL into `{ top, sub }`. Returns null when the URL isn't
 * one of ours.
 */
export const routeFromUrl = ( url ) => {
	try {
		const u    = new URL( url, window.location.origin );
		const page = u.searchParams.get( 'page' ) || '';
		if ( 'isoft-fmf-licenses' === page ) {
			return { top: 'licenses', sub: null };
		}
		if ( 'isoft-fmf-settings' === page ) {
			const tab = u.searchParams.get( 'tab' ) || 'general';
			return {
				top: 'settings',
				sub: SUBS_BY_TOP.settings.includes( tab ) ? tab : 'general',
			};
		}
		if ( TOOLS_URL_TO_SUB[ page ] ) {
			return { top: 'tools', sub: TOOLS_URL_TO_SUB[ page ] };
		}
		return null;
	} catch ( _err ) {
		return null;
	}
};

/** Build the canonical URL for a { top, sub } pair. */
export const urlForRoute = ( top, sub ) => {
	const u = new URL( window.location.href );
	u.searchParams.set( 'post_type', 'isoft_fmf_file' );

	// Wipe non-owned params so state doesn't leak between sub-tab
	// swaps.
	[ ...u.searchParams.keys() ]
		.filter( ( k ) => k !== 'post_type' )
		.forEach( ( k ) => u.searchParams.delete( k ) );

	if ( 'licenses' === top ) {
		u.searchParams.set( 'page', 'isoft-fmf-licenses' );
	} else if ( 'settings' === top ) {
		u.searchParams.set( 'page', 'isoft-fmf-settings' );
		if ( sub && SUBS_BY_TOP.settings.includes( sub ) ) {
			u.searchParams.set( 'tab', sub );
		}
	} else if ( 'tools' === top ) {
		if ( 'log' === sub ) {
			u.searchParams.set( 'page', 'isoft-fmf-log' );
		} else if ( 'broken-links' === sub ) {
			u.searchParams.set( 'page', 'isoft-fmf-broken-links' );
		} else {
			u.searchParams.set( 'page', 'isoft-fmf-stats' );
		}
	}

	return u.pathname + u.search;
};

/**
 * Wire up the router for a specific top page. Returns an object with
 * `navigate(top, sub)` for sub-tab swaps and `destroy()` to unsubscribe.
 *
 * The router only handles routes whose `top` matches `activeTop` —
 * anything else is a cross-page navigation and gets ignored so
 * default WP admin behaviour (full navigation) takes over.
 *
 * @param {string}                                activeTop   Which top section this page renders.
 * @param {(top: string, sub: ?string) => void}   onNavigate  Fired on every intra-top route change.
 */
export const attachRouter = ( activeTop, onNavigate ) => {
	const listeners = [];

	const onPopState = () => {
		const route = routeFromUrl( window.location.href );
		if ( route && route.top === activeTop ) {
			onNavigate( route.top, route.sub );
		}
	};
	window.addEventListener( 'popstate', onPopState );
	listeners.push( () => window.removeEventListener( 'popstate', onPopState ) );

	const navigate = ( top, sub ) => {
		if ( top !== activeTop ) {
			// Cross-page nav goes through WP admin's normal full
			// navigation; the caller should just let the browser
			// follow the anchor href.
			return;
		}
		const target = urlForRoute( top, sub );
		if ( target !== window.location.pathname + window.location.search ) {
			window.history.pushState( { top, sub }, '', target );
		}
		onNavigate( top, sub );
	};

	return {
		navigate,
		destroy: () => listeners.forEach( ( fn ) => fn() ),
	};
};
