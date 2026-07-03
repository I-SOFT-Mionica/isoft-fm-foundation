/**
 * Client-side router for the admin shell.
 *
 * 0.12.8: the routing state is now `{ top, sub }`. `top` is one of
 * licenses|tools|settings; `sub` is which sub-tab is active inside
 * that top (e.g. tools.stats, settings.general). Licenses has no
 * sub-tabs so its sub is always null.
 *
 * URL mapping (the shape browsers see):
 *   ?page=isoft-fmf-licenses                       → { top: licenses, sub: null }
 *   ?page=isoft-fmf-tools                          → { top: tools, sub: stats }
 *   ?page=isoft-fmf-stats                          → { top: tools, sub: stats } (legacy)
 *   ?page=isoft-fmf-log                            → { top: tools, sub: log } (legacy)
 *   ?page=isoft-fmf-broken-links                   → { top: tools, sub: broken-links } (legacy)
 *   ?page=isoft-fmf-settings&tab=general           → { top: settings, sub: general }
 *
 * The legacy sub-URLs still resolve on the server (see
 * ISOFT_FMF_Settings::register_menu — they stay registered even after
 * consolidate_tools_menu removes them from the sidebar) so bookmarks
 * and inbound links keep working; the shell just enters with the
 * right sub-tab active.
 */

/** Top-level sections the router owns. */
export const TOPS = [ 'licenses', 'tools', 'settings' ];

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
 * Parse a URL into a {top, sub} pair. Returns null when the URL isn't
 * one of ours (WP core admin pages, external links, etc.).
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

/** Build the canonical URL for a {top, sub} pair. */
export const urlForRoute = ( top, sub ) => {
	const u = new URL( window.location.href );
	u.searchParams.set( 'post_type', 'isoft_fmf_file' );

	// Wipe non-owned params — the sub-tab is either encoded in `page`
	// (for tools legacy URLs) or in `tab` (settings). Everything else
	// leaks across sections.
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
		// Route Tools sub-tabs through the legacy page slugs so the
		// URL still hits a real submenu callback. Bookmarks land on
		// the same shell either way.
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
 * Wire up the router. Returns an unsubscribe function via .destroy().
 *
 * @param {(top: string, sub: ?string) => void} onNavigate Fired on every route change.
 */
export const attachRouter = ( onNavigate ) => {
	const listeners = [];

	const onPopState = () => {
		const route = routeFromUrl( window.location.href );
		if ( route ) {
			onNavigate( route.top, route.sub );
		}
	};
	window.addEventListener( 'popstate', onPopState );
	listeners.push( () => window.removeEventListener( 'popstate', onPopState ) );

	const navigate = ( top, sub ) => {
		if ( ! TOPS.includes( top ) ) {
			return;
		}
		const target = urlForRoute( top, sub );
		if ( target !== window.location.pathname + window.location.search ) {
			window.history.pushState( { top, sub }, '', target );
		}
		onNavigate( top, sub );
	};

	// Hijack WP #adminmenu links that point at one of our pages, so
	// switching sidebars doesn't do a full navigation. Modifier-key
	// clicks (⌘/Ctrl/Shift/Alt) fall through to default behaviour so
	// users who want a new tab still get one.
	const hijack = ( anchor ) => {
		const onClick = ( e ) => {
			if ( e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0 ) {
				return;
			}
			const route = routeFromUrl( anchor.href );
			if ( ! route ) {
				return;
			}
			e.preventDefault();
			navigate( route.top, route.sub );
		};
		anchor.addEventListener( 'click', onClick );
		listeners.push( () => anchor.removeEventListener( 'click', onClick ) );
	};

	document
		.querySelectorAll( '#adminmenu a[href*="page=isoft-fmf-"]' )
		.forEach( hijack );

	return {
		navigate,
		destroy: () => listeners.forEach( ( fn ) => fn() ),
	};
};
