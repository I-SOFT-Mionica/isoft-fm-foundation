/**
 * Admin shell — SPA entry point for the Downloads > * admin pages.
 *
 * 0.12.9: Each top section (Licenses, Tools, Settings) is its own
 * standalone admin page — no top-level tab strip joins them. The
 * shared bundle still exists (one webpack entry containing all three
 * section components), but each page load only renders the section
 * corresponding to `bootstrap.active.top`. Cross-page navigation is
 * whatever WordPress does natively (a full page reload); the bundle
 * is browser-cached so subsequent loads only pay PHP boot + parse-
 * cached-bundle + hydrate the active section.
 *
 * Within a page, sub-tab nav (Tools' Stats/Log/BrokenLinks strip,
 * Settings' vertical sidebar) IS client-side — router.js handles
 * pushState + popstate for `?tab=` / `?page=isoft-fmf-<sub>` URL
 * shape changes so back/forward and reload behave sanely.
 *
 * The PHP shell (class-admin-shell.php + admin/views/admin-shell-mount.php)
 * emits one mount div carrying `data-bootstrap` with:
 *   - active = { top, sub }: which top section to render + initial sub
 *   - badgeCount: broken-links count (drives the Tools sub-nav badge)
 *   - one slice for the active top section
 */

import {
	createRoot,
	render,
	useState,
	useEffect,
	useCallback,
	useRef,
} from '@wordpress/element';

import { attachRouter } from './router';

import LicensesSection from './sections/licenses';
import ToolsSection    from './sections/tools';
import SettingsSection from './sections/settings';

const SECTION_COMPONENTS = {
	licenses: LicensesSection,
	tools:    ToolsSection,
	settings: SettingsSection,
};

const Shell = ( { initialActive, bootstrap } ) => {
	const activeTop = initialActive.top;
	const [ activeSub, setActiveSub ] = useState( initialActive.sub );

	const routerRef = useRef( null );

	const handleNavigate = useCallback( ( top, sub ) => {
		// The router only fires with top === activeTop for this page —
		// cross-top navigation goes through the WP admin sidebar (full
		// page reload), not client-side.
		if ( top !== activeTop ) {
			return;
		}
		if ( sub != null ) {
			setActiveSub( sub );
		}
	}, [ activeTop ] );

	useEffect( () => {
		routerRef.current = attachRouter( activeTop, handleNavigate );
		return () => {
			routerRef.current?.destroy();
			routerRef.current = null;
		};
	}, [ activeTop, handleNavigate ] );

	const onSelectSub = useCallback( ( sub ) => {
		routerRef.current?.navigate( activeTop, sub );
	}, [ activeTop ] );

	const brokenCount = parseInt( bootstrap?.badgeCount, 10 ) || 0;

	const Component = SECTION_COMPONENTS[ activeTop ];
	if ( ! Component ) {
		return null;
	}

	const topBootstrap = bootstrap?.[ activeTop ] || {};

	return (
		<div className="isoft-fmf-shell">
			<Component
				bootstrap={ topBootstrap }
				activeSub={ activeSub }
				onSelectSub={ onSelectSub }
				brokenCount={ brokenCount }
			/>
		</div>
	);
};

const mountNode = document.getElementById( 'isoft-fmf-admin-root' );
if ( mountNode ) {
	let bootstrap = {};
	try {
		bootstrap = JSON.parse( mountNode.getAttribute( 'data-bootstrap' ) || '{}' );
	} catch ( _err ) {
		bootstrap = {};
	}

	const initialActive = bootstrap?.active || { top: 'licenses', sub: null };

	if ( typeof createRoot === 'function' ) {
		createRoot( mountNode ).render(
			<Shell initialActive={ initialActive } bootstrap={ bootstrap } />
		);
	} else if ( typeof render === 'function' ) {
		render(
			<Shell initialActive={ initialActive } bootstrap={ bootstrap } />,
			mountNode
		);
	}
}
