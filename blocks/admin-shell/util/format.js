/**
 * Shared formatters for the admin shell sections.
 *
 * Extracted in 0.12.6 when the 5 per-page bundles collapsed into a
 * single admin-shell entry — pre-collapse copies of formatBytes lived
 * in blocks/stats-page and blocks/editor-sidebar, and formatDate lived
 * in blocks/log-page. Only the admin-shell copy remains; editor-sidebar
 * keeps its local copy (out of scope for the SPA-shell refactor).
 */

import { dateI18n, getSettings as getDateSettings } from '@wordpress/date';

export const formatBytes = ( bytes ) => {
	const n = parseInt( bytes, 10 ) || 0;
	if ( n >= 1073741824 ) {
		return ( n / 1073741824 ).toFixed( 2 ) + ' GB';
	}
	if ( n >= 1048576 ) {
		return ( n / 1048576 ).toFixed( 2 ) + ' MB';
	}
	if ( n >= 1024 ) {
		return ( n / 1024 ).toFixed( 1 ) + ' KB';
	}
	return n + ' B';
};

/**
 * Render a MySQL / ISO date using the WP site's Date + Time formats
 * (per [[use-wp-date-formats]]). Falls back to the raw value on parse
 * failure so admins see something rather than "Invalid Date".
 */
export const formatDate = ( raw ) => {
	if ( ! raw ) {
		return '';
	}
	try {
		const { formats } = getDateSettings();
		const fmt         = `${ formats.date } ${ formats.time }`;
		return dateI18n( fmt, raw );
	} catch ( _err ) {
		return String( raw );
	}
};

/** YYYY-MM-DD for a UTC-aligned date offset (in days) from today. */
export const offsetDay = ( daysAgo ) => {
	const d = new Date();
	d.setUTCDate( d.getUTCDate() - daysAgo );
	return d.toISOString().slice( 0, 10 );
};

/** "M YYYY" for a YYYY-MM-DD string, e.g. "Jun 2026". */
export const monthLabel = ( ymd ) => {
	const d = new Date( ymd + 'T00:00:00Z' );
	if ( isNaN( d.getTime() ) ) {
		return ymd;
	}
	return d.toLocaleDateString( undefined, {
		month:    'short',
		year:     'numeric',
		timeZone: 'UTC',
	} );
};
