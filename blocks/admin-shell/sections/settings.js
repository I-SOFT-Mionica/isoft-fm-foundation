/**
 * Settings section — vertical sub-nav card layout.
 *
 * 0.12.8 rewrite: the horizontal TabPanel from earlier phases is gone.
 * WP-native pattern (Site Health, WooCommerce Settings) puts sub-nav
 * items in a left sidebar with content on the right inside one card.
 * All six sub-tabs live in the same UX now — General / Display /
 * Security / Advanced share the schema-driven form; Maintenance /
 * Extensions render server-inlined PHP HTML via
 * dangerouslySetInnerHTML.
 *
 * The Maintenance and Extensions forms POST to options.php /
 * admin-post.php as they always did. After a form submit the browser
 * reloads to the shell — no React state to manage on either sub-tab.
 */

import {
	Notice,
	Spinner,
	Button,
	ToggleControl,
	SelectControl,
	TextControl,
	TextareaControl,
	__experimentalNumberControl as NumberControl,
} from '@wordpress/components';
import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

const SETTINGS_ROUTE = '/isoft-fm-foundation/v1/settings';

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
			help:    __( 'Requires the Imagick PHP extension. Off by default — turn on per-install when Imagick is verified available.', 'isoft-fm-foundation' ),
			default: 0,
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
			key:         'isoft_fmf_default_button_text',
			label:       __( 'Default Button Text', 'isoft-fm-foundation' ),
			type:        'text',
			placeholder: __( 'Download', 'isoft-fm-foundation' ),
			help:        __( 'Text shown on download buttons site-wide. Leave empty to use "Download".', 'isoft-fm-foundation' ),
			default:     '',
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
				{ label: __( 'Open in new tab',     'isoft-fm-foundation' ), value: '_blank' },
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
				{ label: 'X-Sendfile (Apache)',                      value: 'xsendfile' },
				{ label: 'X-Accel-Redirect (Nginx)',                 value: 'xaccel' },
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
			key:         'isoft_fmf_block_user_agents',
			label:       __( 'User-Agent Blocklist', 'isoft-fm-foundation' ),
			type:        'textarea',
			rows:        6,
			placeholder: 'curl\nwget\nHeadlessChrome\nSemrushBot',
			help:        __( 'One pattern per line. Each line matches as a case-insensitive substring against the request User-Agent header — e.g. "curl" blocks "curl/7.88.1". Empty lines and requests with no User-Agent header are not blocked.', 'isoft-fm-foundation' ),
			default:     '',
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

const SCHEMA_TABS = [ 'general', 'display', 'security', 'advanced' ];

const normalise = ( field, raw ) => {
	if ( field.type === 'toggle' ) {
		return !! parseInt( raw, 10 );
	}
	if ( field.type === 'number' ) {
		return parseInt( raw, 10 ) || 0;
	}
	return raw == null ? '' : String( raw );
};

const serialise = ( field, value ) => {
	if ( field.type === 'toggle' ) {
		return value ? 1 : 0;
	}
	if ( field.type === 'number' ) {
		return parseInt( value, 10 ) || 0;
	}
	return value == null ? '' : String( value );
};

const shapeValues = ( schema, payload ) => {
	const next = {};
	for ( const field of schema ) {
		const raw = payload && Object.prototype.hasOwnProperty.call( payload, field.key )
			? payload[ field.key ]
			: field.default;
		next[ field.key ] = normalise( field, raw == null ? field.default : raw );
	}
	return next;
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
					__next40pxDefaultSize
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

const SettingsForm = ( { tab, initialPayload } ) => {
	const schema  = TAB_SCHEMAS[ tab ] || [];
	const inlined = initialPayload || null;

	const [ values, setValues ]   = useState( () => ( inlined ? shapeValues( schema, inlined ) : null ) );
	const [ initial, setInitial ] = useState( () => ( inlined ? shapeValues( schema, inlined ) : null ) );
	const [ loading, setLoading ] = useState( ! inlined );
	const [ saving, setSaving ]   = useState( false );
	const [ error, setError ]     = useState( null );
	const [ notice, setNotice ]   = useState( null );

	const skipFirstFetch = useRef( !! inlined );

	useEffect( () => {
		if ( skipFirstFetch.current ) {
			skipFirstFetch.current = false;
			return;
		}
		setLoading( true );
		setError( null );
		apiFetch( { path: SETTINGS_ROUTE } )
			.then( ( payload ) => {
				const next = shapeValues( schema, payload );
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
			<div>
				{ error
					? <Notice status="error" isDismissible={ false }>{ error }</Notice>
					: <p><Spinner /></p>
				}
			</div>
		);
	}

	return (
		<div style={ { maxWidth: '720px' } }>
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

const RawHtmlPanel = ( { html, label } ) => {
	if ( ! html ) {
		return (
			<p className="description">
				{ label } { __( '— content unavailable.', 'isoft-fm-foundation' ) }
			</p>
		);
	}
	// PHP already escaped its output via esc_* / wp_kses_post at
	// render time, so injecting the string directly is safe.
	return <div dangerouslySetInnerHTML={ { __html: html } } />;
};

const SUB_TABS = [
	{ id: 'general',     label: () => __( 'General',     'isoft-fm-foundation' ) },
	{ id: 'display',     label: () => __( 'Display',     'isoft-fm-foundation' ) },
	{ id: 'security',    label: () => __( 'Security',    'isoft-fm-foundation' ) },
	{ id: 'advanced',    label: () => __( 'Advanced',    'isoft-fm-foundation' ) },
	{ id: 'maintenance', label: () => __( 'Maintenance', 'isoft-fm-foundation' ) },
	{ id: 'extensions',  label: () => __( 'Extensions',  'isoft-fm-foundation' ) },
];

/**
 * Read a PHP-rendered tab's HTML from the sibling <script type="text/html">
 * blocks emitted by admin-shell-mount.php. Returns '' if the block
 * isn't present (shell mounted on a non-Settings URL, or PHP failed
 * to capture the view).
 */
const readTabHtml = ( tab ) => {
	const el = document.getElementById( `isoft-fmf-tab-${ tab }` );
	return el ? el.textContent : '';
};

const SettingsSection = ( { bootstrap, activeSub, onSelectSub } ) => {
	const initialTab    = bootstrap?.initialTab || 'general';
	const initialValues = bootstrap?.initialValues || null;
	const maintenanceHtml = readTabHtml( 'maintenance' );
	const extensionsHtml  = readTabHtml( 'extensions' );

	const [ localSub, setLocalSub ] = useState( initialTab );
	const effectiveSub = activeSub || localSub;

	const handleSelect = ( sub ) => {
		setLocalSub( sub );
		onSelectSub?.( sub );
	};

	return (
		<div className="isoft-fmf-settings">
			<h1 className="wp-heading-inline">
				{ __( 'Settings', 'isoft-fm-foundation' ) }
			</h1>
			<hr className="wp-header-end" />

			<nav className="nav-tab-wrapper isoft-fmf-settings-nav" style={ { marginTop: 0 } }>
				{ SUB_TABS.map( ( sub ) => {
					const isActive = sub.id === effectiveSub;
					return (
						<a
							key={ sub.id }
							href={ `?post_type=isoft_fmf_file&page=isoft-fmf-settings&tab=${ sub.id }` }
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
						</a>
					);
				} ) }
			</nav>

			<div className="isoft-fmf-settings__body" style={ { marginTop: '16px' } }>
				{ SCHEMA_TABS.includes( effectiveSub ) && (
					<SettingsForm
						tab={ effectiveSub }
						initialPayload={ effectiveSub === initialTab ? initialValues : null }
					/>
				) }
				{ 'maintenance' === effectiveSub && (
					<RawHtmlPanel html={ maintenanceHtml } label={ __( 'Maintenance', 'isoft-fm-foundation' ) } />
				) }
				{ 'extensions' === effectiveSub && (
					<RawHtmlPanel html={ extensionsHtml } label={ __( 'Extensions', 'isoft-fm-foundation' ) } />
				) }
			</div>
		</div>
	);
};

export default SettingsSection;
