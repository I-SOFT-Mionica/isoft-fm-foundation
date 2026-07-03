/**
 * Client-side router for the admin shell.
 *
 * Solves the 5+ second WP admin-nav lag: instead of full page reloads
 * between Downloads > Licenses / Stats / Log / Broken Links / Settings,
 * every navigation is a client-side section swap. Full reload only
 * happens on first entry to the shell or when a user hits a WP
 * admin-menu link and the click hijack fails.
 *
 * Two navigation surfaces:
 *   1. Internal tab strip inside the shell (React nav.js) — always
 *      client-side; pushes state via history.pushState.
 *   2. WP #adminmenu links pointing to our 5 pages — hijacked on
 *      shell mount; if the hijack fires, same client-side swap.
 *      Fallback: default WP full-page nav happens, shell re-mounts,
 *      cached bundle serves. Still faster than pre-0.12.6.
 */

/** Sections the router owns. Kept in sync with SECTION_MAP on the PHP side. */
export const SECTIONS = [
	'licenses',
	'stats',
	'log',
	'broken-links',
	'settings',
];

/** Map section slug -> URL params. All 5 live under the isoft_fmf_file CPT admin menu. */
export const sectionSlug = ( section ) => `isoft-fmf-${ section }`;

export const sectionFromUrl = ( url ) => {
	try {
		const u    = new URL( url, window.location.origin );
		const page = u.searchParams.get( 'page' ) || '';
		const match = page.match( /^isoft-fmf-(licenses|stats|log|broken-links|settings)$/ );
		return match ? match[ 1 ] : null;
	} catch ( _err ) {
		return null;
	}
};

export const urlForSection = ( section ) => {
	const u = new URL( window.location.href );
	u.searchParams.set( 'post_type', 'isoft_fmf_file' );
	u.searchParams.set( 'page', sectionSlug( section ) );
	// Preserve nothing else — section-specific query params (?tab=,
	// ?filter_download=) would leak between sections and confuse
	// bootstrap payload consumers. Sections that want deep-linking
	// can push their own state after mount.
	[ ...u.searchParams.keys() ]
		.filter( ( k ) => k !== 'post_type' && k !== 'page' )
		.forEach( ( k ) => u.searchParams.delete( k ) );
	return u.pathname + u.search;
};

/**
 * Wire up the router. Returns an unsubscribe function.
 *
 * @param {(section: string) => void} onNavigate Called whenever the active section changes.
 */
export const attachRouter = ( onNavigate ) => {
	const listeners = [];

	// History back/forward.
	const onPopState = () => {
		const section = sectionFromUrl( window.location.href );
		if ( section ) {
			onNavigate( section );
		}
	};
	window.addEventListener( 'popstate', onPopState );
	listeners.push( () => window.removeEventListener( 'popstate', onPopState ) );

	// WP admin-menu click hijack. Query on attach — WP renders the
	// menu server-side so all anchors exist by the time our bundle
	// runs. If a plugin re-renders adminmenu later, the hijack won't
	// cover the new anchors; the fallback (default nav → shell
	// re-mount → cached bundle) still applies.
	const hijack = ( anchor ) => {
		const onClick = ( e ) => {
			// Respect modifier keys — user probably wants a new tab.
			if ( e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0 ) {
				return;
			}
			const section = sectionFromUrl( anchor.href );
			if ( ! section ) {
				return;
			}
			e.preventDefault();
			navigate( section );
		};
		anchor.addEventListener( 'click', onClick );
		listeners.push( () => anchor.removeEventListener( 'click', onClick ) );
	};

	const navigate = ( section ) => {
		if ( ! SECTIONS.includes( section ) ) {
			return;
		}
		const target = urlForSection( section );
		if ( target !== window.location.pathname + window.location.search ) {
			window.history.pushState( { section }, '', target );
		}
		onNavigate( section );
	};

	document
		.querySelectorAll( '#adminmenu a[href*="page=isoft-fmf-"]' )
		.forEach( hijack );

	return {
		navigate,
		destroy: () => listeners.forEach( ( fn ) => fn() ),
	};
};
