/**
 * Admin shell — SPA entry point for the 5 Downloads > * admin pages.
 *
 * 0.12.6 replaces five per-page bundles (licenses-page.js, stats-page.js,
 * log-page.js, broken-links-page.js, settings-page.js) with this single
 * entry so:
 *
 *   1. @wordpress/dataviews is bundled ONCE. Pre-0.12.6, Log and Broken
 *      Links each carried ~220 KB of DataViews — clicking between them
 *      re-downloaded the same code.
 *   2. Navigation between the 5 sections is client-side via
 *      router.js. First entry to the shell is still bounded by WP admin
 *      PHP boot (~1.5-2s); subsequent nav is sub-500ms.
 *   3. Sections mount lazily on first visit and stay mounted (hidden
 *      via CSS). Re-visiting Log preserves its DataViews search / page
 *      / filter state.
 *
 * The PHP shell (class-admin-shell.php + admin/views/admin-shell-mount.php)
 * emits one mount div with:
 *   - data-section=<initial section slug>
 *   - data-bootstrap=<JSON blob keyed by section, containing each
 *     section's server-computed initial payload>
 *
 * See docs/architecture.md for the full sequence.
 */

import { createRoot, render, useState, useEffect, useCallback, useMemo, useRef } from '@wordpress/element';

import { SectionNav } from './nav';
import { attachRouter, SECTIONS } from './router';

// Each section is a lazy import to keep the shell entry small and let
// section bundles evict from cache independently. React.lazy expects a
// default export from the module; each section file exports its top
// component as default.
//
// Not using React.lazy right now — sections are eagerly imported so
// the shell mounts synchronously and the section swap doesn't flash a
// Suspense fallback. If bundle grows past 350 KB the switch to
// React.lazy is a one-line change per section.
import LicensesSection    from './sections/licenses';
import StatsSection       from './sections/stats';
import LogSection         from './sections/log';
import BrokenLinksSection from './sections/broken-links';
import SettingsSection    from './sections/settings';

const SECTION_COMPONENTS = {
	'licenses':     LicensesSection,
	'stats':        StatsSection,
	'log':          LogSection,
	'broken-links': BrokenLinksSection,
	'settings':     SettingsSection,
};

const Shell = ( { initialSection, bootstrap } ) => {
	const [ active, setActive ] = useState( initialSection );

	// Track which sections have been visited. Visited sections stay in
	// the tree, hidden via CSS, so returning to them preserves their
	// React state (DataViews search + page, Settings dirty form, etc.).
	const [ visited, setVisited ] = useState( () => new Set( [ initialSection ] ) );

	const routerRef = useRef( null );

	const handleNavigate = useCallback( ( section ) => {
		if ( ! SECTIONS.includes( section ) ) {
			return;
		}
		setActive( section );
		setVisited( ( prev ) => {
			if ( prev.has( section ) ) {
				return prev;
			}
			const next = new Set( prev );
			next.add( section );
			return next;
		} );
	}, [] );

	useEffect( () => {
		routerRef.current = attachRouter( handleNavigate );
		return () => {
			routerRef.current?.destroy();
			routerRef.current = null;
		};
	}, [ handleNavigate ] );

	const onSelect = useCallback( ( section ) => {
		routerRef.current?.navigate( section );
	}, [] );

	// Broken-links badge count for the nav strip. Comes from the
	// broken-links bootstrap payload; if the bootstrap is missing (e.g.
	// initial section is elsewhere and broken-links wasn't seeded), the
	// badge silently omits — it re-populates once broken-links mounts
	// and fetches fresh data.
	const brokenCount = parseInt( bootstrap?.[ 'broken-links' ]?.initialTotal, 10 ) || 0;

	const mountedSections = useMemo( () => Array.from( visited ), [ visited ] );

	return (
		<div className="isoft-fmf-shell">
			<SectionNav active={ active } onSelect={ onSelect } brokenCount={ brokenCount } />
			<div className="isoft-fmf-shell__body">
				{ mountedSections.map( ( section ) => {
					const Component = SECTION_COMPONENTS[ section ];
					if ( ! Component ) {
						return null;
					}
					return (
						<div
							key={ section }
							className="isoft-fmf-shell__section"
							hidden={ section !== active }
						>
							<Component bootstrap={ bootstrap?.[ section ] || {} } />
						</div>
					);
				} ) }
			</div>
		</div>
	);
};

// Bootstrap. PHP shell emits ONE mount node with the initial section +
// a JSON blob keyed by section carrying each section's server-computed
// initial state (retention days, purge nonce, initial counts, etc.).
const mountNode = document.getElementById( 'isoft-fmf-admin-root' );
if ( mountNode ) {
	const initialSection = mountNode.getAttribute( 'data-section' ) || 'licenses';

	let bootstrap = {};
	try {
		bootstrap = JSON.parse( mountNode.getAttribute( 'data-bootstrap' ) || '{}' );
	} catch ( _err ) {
		bootstrap = {};
	}

	if ( typeof createRoot === 'function' ) {
		createRoot( mountNode ).render(
			<Shell initialSection={ initialSection } bootstrap={ bootstrap } />
		);
	} else if ( typeof render === 'function' ) {
		render(
			<Shell initialSection={ initialSection } bootstrap={ bootstrap } />,
			mountNode
		);
	}
}
