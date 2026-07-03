/**
 * Admin shell — SPA entry point for the Downloads > * admin pages.
 *
 * 0.12.8: three top-level sections (Licenses / Tools / Settings). Tools
 * houses Statistics / Download Log / Broken Links as horizontal sub-
 * tabs; Settings houses General / Display / Security / Advanced /
 * Maintenance / Extensions as a vertical sidebar card.
 *
 * The PHP shell (class-admin-shell.php + admin/views/admin-shell-mount.php)
 * emits one mount div with `data-bootstrap` carrying:
 *   - active = { top, sub }: initial routing state
 *   - badgeCount: broken-links count (drives both the top nav Tools
 *     badge and the Tools sub-nav Broken Links badge)
 *   - one slice for the active top section
 *
 * See docs/architecture.md for the full sequence.
 */

import {
	createRoot,
	render,
	useState,
	useEffect,
	useCallback,
	useMemo,
	useRef,
} from '@wordpress/element';

import { SectionNav } from './nav';
import { attachRouter, TOPS } from './router';

import LicensesSection from './sections/licenses';
import ToolsSection    from './sections/tools';
import SettingsSection from './sections/settings';

const SECTION_COMPONENTS = {
	licenses: LicensesSection,
	tools:    ToolsSection,
	settings: SettingsSection,
};

const Shell = ( { initialActive, bootstrap } ) => {
	const [ activeTop, setActiveTop ] = useState( initialActive.top );
	const [ activeSubByTop, setActiveSubByTop ] = useState( () => ( {
		licenses: null,
		tools:    'tools' === initialActive.top ? initialActive.sub : 'stats',
		settings: 'settings' === initialActive.top ? initialActive.sub : 'general',
	} ) );

	// Sections mount lazily on first visit and stay in the tree behind
	// [hidden] so revisits preserve React state (DataViews search,
	// Settings dirty form, ...).
	const [ visited, setVisited ] = useState( () => new Set( [ initialActive.top ] ) );

	const routerRef = useRef( null );

	const handleNavigate = useCallback( ( top, sub ) => {
		if ( ! TOPS.includes( top ) ) {
			return;
		}
		setActiveTop( top );
		if ( sub != null ) {
			setActiveSubByTop( ( prev ) => ( { ...prev, [ top ]: sub } ) );
		}
		setVisited( ( prev ) => {
			if ( prev.has( top ) ) {
				return prev;
			}
			const next = new Set( prev );
			next.add( top );
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

	const onSelectTop = useCallback( ( top ) => {
		routerRef.current?.navigate( top, activeSubByTop[ top ] );
	}, [ activeSubByTop ] );

	const onSelectSub = useCallback( ( top, sub ) => {
		routerRef.current?.navigate( top, sub );
	}, [] );

	const brokenCount = parseInt( bootstrap?.badgeCount, 10 ) || 0;

	const mountedTops = useMemo( () => Array.from( visited ), [ visited ] );

	// Bootstrap slices per top section. `bootstrap[top]` is the slice
	// the PHP side computed for the active top; sibling tops receive
	// an empty object and hydrate over REST on first visit.
	const perTopBootstrap = useMemo(
		() => ( {
			licenses: bootstrap?.licenses || {},
			tools:    bootstrap?.tools    || {},
			settings: bootstrap?.settings || {},
		} ),
		[ bootstrap ]
	);

	return (
		<div className="isoft-fmf-shell">
			<SectionNav
				activeTop={ activeTop }
				onSelect={ onSelectTop }
				brokenCount={ brokenCount }
			/>
			<div className="isoft-fmf-shell__body">
				{ mountedTops.map( ( top ) => {
					const Component = SECTION_COMPONENTS[ top ];
					if ( ! Component ) {
						return null;
					}
					return (
						<div
							key={ top }
							className="isoft-fmf-shell__section"
							hidden={ top !== activeTop }
						>
							<Component
								bootstrap={ perTopBootstrap[ top ] }
								activeSub={ activeSubByTop[ top ] }
								onSelectSub={ ( sub ) => onSelectSub( top, sub ) }
								brokenCount={ brokenCount }
							/>
						</div>
					);
				} ) }
			</div>
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
