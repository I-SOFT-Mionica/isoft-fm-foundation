/**
 * React app for the Settings admin page
 * (Downloads > Settings — 4 option-driven tabs).
 *
 * 0.12.3 scope, sub-PR 4 of 4. Pure form-control surface. Each tab
 * renders a vertical stack of @wordpress/components controls bound to
 * the /settings REST endpoint landed in phase 0.12.0. State is held
 * in a single useState({ values }) — small enough that a Redux slice
 * would be overkill.
 *
 * Mount mode:
 *   - PHP renders the nav-tab-wrapper (unchanged); when the active tab
 *     is one of the 4 option tabs, it mounts <div id="…-settings-root">
 *     with data-tab="general|display|security|advanced".
 *   - Maintenance and Extensions tabs are PHP-rendered (action handlers
 *     + marketing copy — no React port benefit). The PHP view branches
 *     before mount, so React is never asked to render them.
 *
 * Save UX:
 *   - One "Save Changes" button per tab; dirty-state guard disables it
 *     until something actually changed. POSTs only the diff (changed
 *     keys) so we don't write 30 options for a one-toggle change.
 *   - Bundle Cache clear, Flush Rewrite, and any other side-effect
 *     actions still go through their existing admin-post.php endpoints
 *     via plain <a href> links — they're nonced server-side already
 *     and don't fit the option-save flow.
 */

import {
	Notice,
	Spinner,
	Button,
	TabPanel,
	ToggleControl,
	SelectControl,
	TextControl,
	TextareaControl,
	__experimentalNumberControl as NumberControl,
} from '@wordpress/components';
import { useState, useEffect, useCallback, createRoot, render } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

const SETTINGS_ROUTE = '/isoft-fm-foundation/v1/settings';

/**
 * Per-tab schema. Each entry: option key, label, type, optional help
 * text + per-type extras. Drives both rendering and the dirty-diff
 * computation. Mirrors the PHP form controls in settings-page.php
 * one-for-one so admins flipping between fallback and React see the
 * same fields in the same order.
 */
const TAB_SCHEMAS = {
	general: [
		{
			key:     'isoft_fmf_default_access_role',
			label:   __( 'Default Access Role', 'isoft-fm-foundation' ),
			type:    'select',
			default: 'public',
			options: [
				{ label: __( 'Public', 'isoft-fm-foundation' ),             value: 'public' },
				{ label: __( 'Subscriber+', 'isoft-fm-foundation' ),        value: 'subscriber' },
				{ label: __( 'Editor+', 'isoft-fm-foundation' ),            value: 'editor' },
				{ label: __( 'Administrator only', 'isoft-fm-foundation' ), value: 'administrator' },
			],
		},
		{
			key:     'isoft_fmf_enable_counting',
			label:   __( 'Count downloads', 'isoft-fm-foundation' ),
			type:    'toggle',
			default: 1,
		},
		{
			key:     'isoft_fmf_enable_logging',
			label:   __( 'Log downloads (timestamp, file, user)', 'isoft-fm-foundation' ),
			type:    'toggle',
			default: 1,
		},
		{
			key:     'isoft_fmf_enable_detailed_logging',
			label:   __( 'Also log IP address, user agent, and referer', 'isoft-fm-foundation' ),
			type:    'toggle',
			help:    __( 'Collects personally identifiable information (PII). Enable only when needed for security investigation.', 'isoft-fm-foundation' ),
			default: 0,
		},
		{
			key:     'isoft_fmf_log_retention_days',
			label:   __( 'Log Retention (days)', 'isoft-fm-foundation' ),
			type:    'number',
			min:     0,
			help:    __( '0 = keep forever.', 'isoft-fm-foundation' ),
			default: 365,
		},
		{
			key:     'isoft_fmf_enable_pdf_thumbnails',
			label:   __( 'Auto-generate thumbnail from PDF first page', 'isoft-fm-foundation' ),
			type:    'toggle',
			default: 1,
		},
		{
			key:     'isoft_fmf_allowed_extensions',
			label:   __( 'Allowed File Extensions', 'isoft-fm-foundation' ),
			type:    'textarea',
			rows:    3,
			help:    __( 'Comma-separated list of permitted extensions. Uploads with unlisted extensions are blocked.', 'isoft-fm-foundation' ),
			default: 'pdf,doc,docx,xls,xlsx,ppt,pptx,odt,ods,odp,txt,csv,zip,rar,7z,jpg,jpeg,png,gif,webp,mp4,mp3,wav',
		},
		{
			key:     'isoft_fmf_cyrillic_titles',
			label:   __( 'Auto-convert upload title to Serbian Cyrillic', 'isoft-fm-foundation' ),
			type:    'toggle',
			help:    __( 'When enabled, the title field is pre-filled with a Cyrillic transliteration of the filename.', 'isoft-fm-foundation' ),
			default: 0,
		},
	],
	display: [
		{
			key:     'isoft_fmf_default_button_text',
			label:   __( 'Default Button Text', 'isoft-fm-foundation' ),
			type:    'text',
			placeholder: __( 'Download', 'isoft-fm-foundation' ),
			help:    __( 'Text shown on download buttons site-wide. Leave empty to use "Download".', 'isoft-fm-foundation' ),
			default: '',
		},
		{
			key:     'isoft_fmf_listing_layout',
			label:   __( 'Default Layout', 'isoft-fm-foundation' ),
			type:    'select',
			default: 'list',
			options: [
				{ label: __( 'List', 'isoft-fm-foundation' ),  value: 'list' },
				{ label: __( 'Grid', 'isoft-fm-foundation' ),  value: 'grid' },
				{ label: __( 'Table', 'isoft-fm-foundation' ), value: 'table' },
			],
		},
		{
			key:     'isoft_fmf_items_per_page',
			label:   __( 'Items Per Page', 'isoft-fm-foundation' ),
			type:    'number',
			min:     1,
			max:     100,
			default: 10,
		},
		{
			key:     'isoft_fmf_show_file_size',
			label:   __( 'Show file size in listings', 'isoft-fm-foundation' ),
			type:    'toggle',
			default: 1,
		},
		{
			key:     'isoft_fmf_show_download_count',
			label:   __( 'Show download count in listings', 'isoft-fm-foundation' ),
			type:    'toggle',
			default: 1,
		},
		{
			key:     'isoft_fmf_show_date',
			label:   __( 'Show date in listings', 'isoft-fm-foundation' ),
			type:    'toggle',
			default: 1,
		},
		{
			key:     'isoft_fmf_external_link_target',
			label:   __( 'External Link Target', 'isoft-fm-foundation' ),
			type:    'select',
			help:    __( 'Where external-URL download buttons open the linked page. Only affects external links — local files always download in place.', 'isoft-fm-foundation' ),
			default: '_blank',
			options: [
				{ label: __( 'Open in new tab',      'isoft-fm-foundation' ), value: '_blank' },
				{ label: __( 'Open in same window', 'isoft-fm-foundation' ), value: '_self' },
			],
		},
		{
			key:     'isoft_fmf_enable_zip_bundle',
			label:   __( 'Show a "Download all as ZIP" button on multi-file downloads', 'isoft-fm-foundation' ),
			type:    'toggle',
			default: 0,
		},
		{
			key:     'isoft_fmf_enable_zip_cache',
			label:   __( 'Cache generated ZIP bundles so repeated downloads serve the same file', 'isoft-fm-foundation' ),
			type:    'toggle',
			default: 0,
		},
		{
			key:     'isoft_fmf_zip_cache_days',
			label:   __( 'ZIP cache duration (days)', 'isoft-fm-foundation' ),
			type:    'number',
			min:     1,
			max:     365,
			default: 7,
		},
	],
	security: [
		{
			key:     'isoft_fmf_serve_method',
			label:   __( 'File Serving Method', 'isoft-fm-foundation' ),
			type:    'select',
			default: 'auto',
			options: [
				{ label: __( 'Auto-detect', 'isoft-fm-foundation' ), value: 'auto' },
				{ label: 'X-Sendfile (Apache)',                       value: 'xsendfile' },
				{ label: 'X-Accel-Redirect (Nginx)',                  value: 'xaccel' },
				{ label: __( 'PHP streaming', 'isoft-fm-foundation' ), value: 'php' },
			],
		},
		{
			key:     'isoft_fmf_rate_limit_per_hour',
			label:   __( 'Rate Limit (per IP/hour)', 'isoft-fm-foundation' ),
			type:    'number',
			min:     0,
			help:    __( '0 = no limit.', 'isoft-fm-foundation' ),
			default: 0,
		},
		{
			key:     'isoft_fmf_hotlink_protection',
			label:   __( 'Block downloads from external referers', 'isoft-fm-foundation' ),
			type:    'toggle',
			default: 0,
		},
		{
			key:     'isoft_fmf_block_user_agents',
			label:   __( 'User-Agent Blocklist', 'isoft-fm-foundation' ),
			type:    'textarea',
			rows:    6,
			placeholder: 'curl\nwget\nHeadlessChrome\nSemrushBot',
			help:    __( 'One pattern per line. Each line matches as a case-insensitive substring against the request User-Agent header — e.g. "curl" blocks "curl/7.88.1". Empty lines and requests with no User-Agent header are not blocked.', 'isoft-fm-foundation' ),
			default: '',
		},
	],
	advanced: [
		{
			key:     'isoft_fmf_archive_slug',
			label:   __( 'Download Archive Slug', 'isoft-fm-foundation' ),
			type:    'text',
			default: 'downloads',
		},
		{
			key:     'isoft_fmf_category_slug',
			label:   __( 'Category Archive Slug', 'isoft-fm-foundation' ),
			type:    'text',
			default: 'download-category',
		},
		{
			key:     'isoft_fmf_tag_slug',
			label:   __( 'Tag Archive Slug', 'isoft-fm-foundation' ),
			type:    'text',
			default: 'download-tag',
		},
		{
			key:     'isoft_fmf_delete_data_on_uninstall',
			label:   __( 'Delete all plugin data when the plugin is uninstalled', 'isoft-fm-foundation' ),
			type:    'toggle',
			help:    __( 'Warning: this will permanently delete all downloads, files, logs, and settings.', 'isoft-fm-foundation' ),
			default: 0,
		},
	],
};

/** Coerce a stored option value into the shape its control expects. */
const normalise = ( field, raw ) => {
	if ( field.type === 'toggle' ) {
		return !! parseInt( raw, 10 );
	}
	if ( field.type === 'number' ) {
		return parseInt( raw, 10 ) || 0;
	}
	return raw == null ? '' : String( raw );
};

/** Convert a control value back to the wire format the REST endpoint
 *  expects (toggles -> 0/1, numbers -> int, rest -> string). */
const serialise = ( field, value ) => {
	if ( field.type === 'toggle' ) {
		return value ? 1 : 0;
	}
	if ( field.type === 'number' ) {
		return parseInt( value, 10 ) || 0;
	}
	return value == null ? '' : String( value );
};

const FieldControl = ( { field, value, onChange } ) => {
	const common = {
		label: field.label,
		help:  field.help,
	};
	switch ( field.type ) {
		case 'toggle':
			return (
				<ToggleControl
					{ ...common }
					checked={ !! value }
					onChange={ onChange }
					__nextHasNoMarginBottom
				/>
			);
		case 'select':
			return (
				<SelectControl
					{ ...common }
					value={ String( value ?? '' ) }
					options={ field.options || [] }
					onChange={ onChange }
					__nextHasNoMarginBottom
				/>
			);
		case 'number':
			return (
				<NumberControl
					{ ...common }
					value={ value }
					min={ field.min }
					max={ field.max }
					onChange={ ( v ) => onChange( v == null ? '' : v ) }
					__nextHasNoMarginBottom
				/>
			);
		case 'textarea':
			return (
				<TextareaControl
					{ ...common }
					value={ value }
					rows={ field.rows || 4 }
					placeholder={ field.placeholder }
					onChange={ onChange }
					__nextHasNoMarginBottom
				/>
			);
		case 'text':
		default:
			return (
				<TextControl
					{ ...common }
					value={ value }
					placeholder={ field.placeholder }
					onChange={ onChange }
					__next40pxDefaultSize
					__nextHasNoMarginBottom
				/>
			);
	}
};

const SettingsApp = ( { tab } ) => {
	const schema = TAB_SCHEMAS[ tab ] || [];

	const [ values, setValues ]     = useState( null );   // hydrated form values
	const [ initial, setInitial ]   = useState( null );   // baseline for dirty-diff
	const [ loading, setLoading ]   = useState( true );
	const [ saving, setSaving ]     = useState( false );
	const [ error, setError ]       = useState( null );
	const [ notice, setNotice ]     = useState( null );

	useEffect( () => {
		setLoading( true );
		setError( null );
		apiFetch( { path: SETTINGS_ROUTE } )
			.then( ( payload ) => {
				const next = {};
				for ( const field of schema ) {
					const raw = payload && Object.prototype.hasOwnProperty.call( payload, field.key )
						? payload[ field.key ]
						: field.default;
					next[ field.key ] = normalise( field, raw == null ? field.default : raw );
				}
				setValues( next );
				setInitial( next );
			} )
			.catch( ( err ) => {
				setError(
					err?.message ||
						__( 'Could not load settings.', 'isoft-fm-foundation' )
				);
			} )
			.finally( () => setLoading( false ) );
	}, [ tab ] );

	const setField = useCallback( ( key, val ) => {
		setValues( ( prev ) => ( prev ? { ...prev, [ key ]: val } : prev ) );
	}, [] );

	const isDirty = values && initial && schema.some(
		( field ) => values[ field.key ] !== initial[ field.key ]
	);

	const save = () => {
		if ( ! isDirty || saving ) {
			return;
		}
		const diff = {};
		for ( const field of schema ) {
			if ( values[ field.key ] !== initial[ field.key ] ) {
				diff[ field.key ] = serialise( field, values[ field.key ] );
			}
		}
		setSaving( true );
		setNotice( null );
		apiFetch( {
			path:   SETTINGS_ROUTE,
			method: 'POST',
			data:   diff,
		} )
			.then( () => {
				setInitial( { ...values } );
				setNotice( {
					status:  'success',
					message: __( 'Settings saved.', 'isoft-fm-foundation' ),
				} );
			} )
			.catch( ( err ) => {
				setNotice( {
					status:  'error',
					message: err?.message || __( 'Save failed.', 'isoft-fm-foundation' ),
				} );
			} )
			.finally( () => setSaving( false ) );
	};

	if ( loading || ! values ) {
		return (
			<div style={ { marginTop: '16px' } }>
				{ error
					? <Notice status="error" isDismissible={ false }>{ error }</Notice>
					: <p><Spinner /></p>
				}
			</div>
		);
	}

	return (
		<div style={ { marginTop: '16px', maxWidth: '720px' } }>
			{ notice && (
				<Notice
					status={ notice.status }
					isDismissible
					onRemove={ () => setNotice( null ) }
				>
					{ notice.message }
				</Notice>
			) }

			<div style={ { display: 'flex', flexDirection: 'column', gap: '20px' } }>
				{ schema.map( ( field ) => (
					<FieldControl
						key={ field.key }
						field={ field }
						value={ values[ field.key ] }
						onChange={ ( v ) => setField( field.key, v ) }
					/>
				) ) }
			</div>

			<div style={ { marginTop: '24px' } }>
				<Button
					variant="primary"
					onClick={ save }
					disabled={ ! isDirty || saving }
					__next40pxDefaultSize
				>
					{ saving
						? __( 'Saving…', 'isoft-fm-foundation' )
						: __( 'Save Changes', 'isoft-fm-foundation' ) }
				</Button>
				{ isDirty && ! saving && (
					<span
						style={ {
							marginLeft: '12px',
							color:      '#646970',
							fontSize:   '12px',
						} }
					>
						{ __( 'Unsaved changes', 'isoft-fm-foundation' ) }
					</span>
				) }
			</div>
		</div>
	);
};

/**
 * Wrapper that owns client-side tab switching for the 4 option tabs.
 * The 6-tab strip is rendered in React so flipping between General /
 * Display / Security / Advanced is instant — no page reload, no
 * bundle re-download. The Maintenance and Extensions tabs are
 * PHP-rendered surfaces, so clicking them does a full navigation to
 * their URL (handed in via data-tab-urls on the mount node).
 *
 * Replaces the PHP nav-tab-wrapper when the React bundle is loaded;
 * the PHP nav stays only for the JS-disabled fallback path.
 */
const SettingsTabs = ( { initialTab, phpTabUrls } ) => {
	const tabs = [
		{ name: 'general',     title: __( 'General', 'isoft-fm-foundation' ) },
		{ name: 'display',     title: __( 'Display', 'isoft-fm-foundation' ) },
		{ name: 'security',    title: __( 'Security', 'isoft-fm-foundation' ) },
		{ name: 'advanced',    title: __( 'Advanced', 'isoft-fm-foundation' ) },
		{ name: 'maintenance', title: __( 'Maintenance', 'isoft-fm-foundation' ) },
		{ name: 'extensions',  title: __( 'Extensions', 'isoft-fm-foundation' ) },
	];

	const onSelect = ( tabName ) => {
		// PHP tabs need a full navigation — their content isn't in the
		// React bundle. Returning early would still leave TabPanel's
		// internal selection in the bad state, but window.location
		// fires before that visual flicker matters.
		if ( phpTabUrls[ tabName ] ) {
			window.location.href = phpTabUrls[ tabName ];
		}
	};

	return (
		<TabPanel
			className="isoft-fmf-settings-tabs"
			activeClass="is-active"
			initialTabName={ initialTab }
			tabs={ tabs }
			onSelect={ onSelect }
		>
			{ ( tab ) => {
				// PHP tabs: handled by onSelect's window.location. While the
				// navigation kicks off, show a Spinner so the area isn't
				// blank.
				if ( phpTabUrls[ tab.name ] ) {
					return (
						<div style={ { padding: '20px', textAlign: 'center' } }>
							<Spinner />
						</div>
					);
				}
				return <SettingsApp tab={ tab.name } />;
			} }
		</TabPanel>
	);
};

const mountNode = document.getElementById( 'isoft-fmf-settings-root' );
if ( mountNode ) {
	const initialTab = mountNode.getAttribute( 'data-tab' ) || 'general';

	// PHP-rendered tab URLs come in as a JSON blob in a data attribute
	// so the React side stays free of admin_url() / current site logic.
	let phpTabUrls = {};
	try {
		phpTabUrls = JSON.parse( mountNode.getAttribute( 'data-php-tab-urls' ) || '{}' );
	} catch ( e ) {
		phpTabUrls = {};
	}

	if ( typeof createRoot === 'function' ) {
		createRoot( mountNode ).render(
			<SettingsTabs initialTab={ initialTab } phpTabUrls={ phpTabUrls } />
		);
	} else if ( typeof render === 'function' ) {
		render(
			<SettingsTabs initialTab={ initialTab } phpTabUrls={ phpTabUrls } />,
			mountNode
		);
	}
}
