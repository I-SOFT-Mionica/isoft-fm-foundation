/**
 * In-shell tab strip. Sits below the WP admin header, above the active
 * section. Styled to match WP admin's `.nav-tab-wrapper` visually so
 * admins don't have to learn a new nav pattern — clicking a tab feels
 * exactly like clicking the corresponding submenu item, except it's
 * instant.
 */

import { __ } from '@wordpress/i18n';

const TABS = [
	{ id: 'licenses',     label: () => __( 'Licenses',     'isoft-fm-foundation' ) },
	{ id: 'stats',        label: () => __( 'Statistics',   'isoft-fm-foundation' ) },
	{ id: 'log',          label: () => __( 'Download Log', 'isoft-fm-foundation' ) },
	{ id: 'broken-links', label: () => __( 'Broken Links', 'isoft-fm-foundation' ) },
	{ id: 'settings',     label: () => __( 'Settings',     'isoft-fm-foundation' ) },
];

export const SectionNav = ( { active, onSelect, brokenCount } ) => (
	<nav className="nav-tab-wrapper isoft-fmf-shell-nav">
		{ TABS.map( ( tab ) => {
			const isActive = tab.id === active;
			const badge    = tab.id === 'broken-links' && brokenCount > 0 && (
				<span className="awaiting-mod isoft-fmf-broken-badge">
					{ brokenCount.toLocaleString() }
				</span>
			);
			return (
				<a
					key={ tab.id }
					href={ `?post_type=isoft_fmf_file&page=isoft-fmf-${ tab.id }` }
					className={ `nav-tab ${ isActive ? 'nav-tab-active' : '' }` }
					onClick={ ( e ) => {
						if ( e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0 ) {
							return;
						}
						e.preventDefault();
						onSelect( tab.id );
					} }
				>
					{ tab.label() }
					{ badge ? ' ' : null }
					{ badge }
				</a>
			);
		} ) }
	</nav>
);
