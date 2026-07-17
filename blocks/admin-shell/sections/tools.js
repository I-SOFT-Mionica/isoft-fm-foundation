/**
 * Tools section — houses Statistics / Download Log / Broken Links as
 * horizontal sub-tabs inside a single card. Consolidates what were
 * three separate sidebar entries + top-level shell tabs pre-0.12.8.
 *
 * The sub-nav is a small hand-rolled tab strip (WP admin
 * `.nav-tab-wrapper` visual style, mirroring the top nav) so users
 * don't have to learn a new pattern per section.
 *
 * Each sub-section is the same React component the pre-0.12.8 shell
 * mounted at the top level (StatsSection / LogSection /
 * BrokenLinksSection) — the port is just a re-parenting, not a
 * rewrite. The Broken Links integrity-check panel is still a PHP
 * fragment rendered above the shell mount on that specific URL.
 */

import { __ } from '@wordpress/i18n';
import { useState, useMemo } from '@wordpress/element';

import StatsSection       from './stats';
import LogSection         from './log';
import BrokenLinksSection from './broken-links';

const SUBS = [
	{ id: 'stats',        label: () => __( 'Statistics',   'isoft-fm-foundation' ) },
	{ id: 'log',          label: () => __( 'Download Log', 'isoft-fm-foundation' ) },
	{ id: 'broken-links', label: () => __( 'Broken Links', 'isoft-fm-foundation' ) },
];

const ToolsSection = ( { bootstrap, activeSub, onSelectSub, brokenCount } ) => {
	const initialSub = SUBS.some( ( s ) => s.id === activeSub ) ? activeSub : 'stats';
	const [ localSub, setLocalSub ] = useState( initialSub );
	// The prop wins if the router pushed a change (URL nav, popstate);
	// the local state only exists to make the tab strip feel immediate.
	const effectiveSub = activeSub || localSub;

	const handleSelect = ( sub ) => {
		setLocalSub( sub );
		onSelectSub?.( sub );
	};

	// Server-inlined slices, keyed to the active sub. Sibling sub-tabs
	// pass no bootstrap — they hydrate over REST on first visit.
	const perSubBootstrap = useMemo(
		() => ( {
			stats:          bootstrap?.stats        || {},
			log:            bootstrap?.log          || {},
			'broken-links': bootstrap?.brokenLinks  || { badgeCount: brokenCount },
		} ),
		[ bootstrap, brokenCount ]
	);

	return (
		<div className="isoft-fmf-tools">
			<nav className="nav-tab-wrapper isoft-fmf-tools-nav" style={ { marginTop: 0 } }>
				{ SUBS.map( ( sub ) => {
					const isActive = sub.id === effectiveSub;
					const badge    = sub.id === 'broken-links' && brokenCount > 0 && (
						<span className="awaiting-mod isoft-fmf-broken-badge">
							{ brokenCount.toLocaleString() }
						</span>
					);
					return (
						<a
							key={ sub.id }
							href={ `?post_type=isoft_fmf_file&page=isoft-fmf-${ sub.id === 'stats' ? 'stats' : sub.id }` }
							className={ `nav-tab ${ isActive ? 'nav-tab-active' : '' }` }
							onClick={ ( e ) => {
								if ( e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0 ) {
									return;
								}
								e.preventDefault();
								handleSelect( sub.id );
							} }
						>
							{ sub.label() }
							{ badge ? ' ' : null }
							{ badge }
						</a>
					);
				} ) }
			</nav>

			<div className="isoft-fmf-tools__body">
				<div hidden={ effectiveSub !== 'stats' }>
					<StatsSection bootstrap={ perSubBootstrap.stats } />
				</div>
				<div hidden={ effectiveSub !== 'log' }>
					<LogSection bootstrap={ perSubBootstrap.log } />
				</div>
				<div hidden={ effectiveSub !== 'broken-links' }>
					<BrokenLinksSection bootstrap={ perSubBootstrap[ 'broken-links' ] } />
				</div>
			</div>
		</div>
	);
};

export default ToolsSection;
