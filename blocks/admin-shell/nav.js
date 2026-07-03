/**
 * In-shell top tab strip — three sections (Licenses / Tools / Settings)
 * as of 0.12.8.
 *
 * The strip sits below the WP admin header, above the active section's
 * body. Styled to match WP admin's `.nav-tab-wrapper` visually so
 * admins don't have to learn a new nav pattern — clicking a tab feels
 * exactly like clicking the corresponding submenu item, except it's
 * instant.
 *
 * The broken-links badge appears on the Tools tab so it's discoverable
 * without opening the sub-tab strip inside Tools.
 */

import { __ } from '@wordpress/i18n';

const TABS = [
	{ id: 'licenses', label: () => __( 'Licenses', 'isoft-fm-foundation' ) },
	{ id: 'tools',    label: () => __( 'Tools',    'isoft-fm-foundation' ) },
	{ id: 'settings', label: () => __( 'Settings', 'isoft-fm-foundation' ) },
];

export const SectionNav = ( { activeTop, onSelect, brokenCount } ) => (
	<nav className="nav-tab-wrapper isoft-fmf-shell-nav">
		{ TABS.map( ( tab ) => {
			const isActive = tab.id === activeTop;
			const badge    = tab.id === 'tools' && brokenCount > 0 && (
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
