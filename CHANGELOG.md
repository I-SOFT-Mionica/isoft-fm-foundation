# Changelog

All notable changes to **I-Soft File Manager: Foundation** (formerly i-Downloads). Format loosely based on [Keep a Changelog](https://keepachangelog.com/). Versions follow [Semantic Versioning](https://semver.org/) once we hit 1.0.0; pre-1.0 bumps are incremental and freely breaking.

## [0.8.3] — 2026-05-31

Plugin Check runtime warnings cleared once CI started running them. All three findings came from the same place: `ISFM_Shortcodes::enqueue_assets()` hooked into `wp_enqueue_scripts` unconditionally.

### Changed
- **`includes/class-shortcodes.php`** — `enqueue_assets()` now early-returns unless a new `page_needs_assets()` helper confirms the page actually renders plugin content (single download, download archive/taxonomy, or a post containing one of our shortcodes or blocks). Public CSS and JS no longer load on every front-end page.
- **`includes/class-shortcodes.php`** — `wp_enqueue_script()` for `isfm-public` switched to the array-args form (`'in_footer' => true, 'strategy' => 'defer'`). Clears `NonBlockingScripts.NoStrategy`.

### CI
- **`.github/workflows/plugin-check.yml`** — replaced the upstream `wordpress/plugin-check-action` wrapper (which silently no-ops on Node 24.16.0 / libuv 1.52.1 when `.wp-env.json` carries URL-source plugins; see [WordPress/plugin-check-action#579](https://github.com/WordPress/plugin-check-action/issues/579)) with a direct `wp-env` invocation. `.wp-env.json` is URL-plugin-free; Plugin Check is installed post-boot via `wp plugin install plugin-check --activate`. `phpVersion: 8.4` lets the plugin actually activate so runtime checks fire. A `wp cli info` step gates Docker actually being up before the check runs — wp-env can exit 0 without the WordPress container.

## [0.8.2] — 2026-05-30

Plugin Check round-2 cleanup.

### Changed
- **`uninstall.php`** — replaced the wildcard `DELETE FROM {$wpdb->options} WHERE option_name LIKE 'isfm_%'` with explicit `delete_option()` calls over an enumerated list (40-ish keys spanning settings, internal state, and legacy entries). Drops the `WordPress.DB.DirectDatabaseQuery.DirectQuery` + `NoCaching` warnings without needing the previous phpcs:ignore. Added matching `delete_transient()` for `isfm_missing_count` and `isfm_stats_overview`, plus `delete_metadata( 'user', 0, '_isfm_allowed_categories', '', true )` to clean per-user ACL meta the wildcard never touched. Per-IP rate-limit transients (`isfm_rl_*`) are dynamic and not enumerated — they self-expire via TTL.

### Fixed
- **`languages/.gitkeep` → `languages/index.php`** with the standard WordPress `// Silence is golden.` content. Plugin Check's `hidden_files` rule flagged the dotfile. The folder still exists for the `Domain Path` header to resolve to.

## [0.8.1] — 2026-05-30

Local Plugin Check fixes — three errors WP.org's tool flagged after WordPress 7.0 released and reviewer-strict variable-prefix warnings.

### Fixed
- **`outdated_tested_upto_header` (ERROR)** — bumped `Tested up to: 7.0` in `readme.txt`. WordPress 7.0 shipped between the rename PR and now.
- **`wp_function_not_compatible_with_requires_wp` (ERROR)** — bumped `Requires at least: 6.7` in both `readme.txt` and the main plugin header. `ISFM_Blocks::register_block_template()` calls `register_block_template()`, a WP 6.7+ function. The `function_exists()` guard prevents fatal errors on older WP but Plugin Check still flags the mismatch.
- **`plugin_header_nonexistent_domain_path` (ERROR)** — recreated `languages/` directory (with a `.gitkeep`) so the `Domain Path: /languages` header in the main file points to an existing folder. Was deleted alongside the stale POT file in 0.8.0.

### Changed
- Added a single `// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Locals passed in by the including class; not actual globals.` line to every view (`admin/views/*.php`), template (`templates/*.php`, `public/views/*.php`), block render (`blocks/*/render.php`), and `uninstall.php`. Plugin Check ignores our `phpcs.xml.dist` exclude patterns for these files, so the suppression has to live in source.

## [0.8.0] — 2026-05-16

Plugin renamed end-to-end. The WordPress.org plugin-review team accepted the new name "I-Soft File Manager: Foundation" with slug `isoft-fm-foundation`. Since the plugin had not yet shipped publicly, no migration path is needed — every identifier moves in lockstep.

### Changed
- **Plugin slug:** `i-downloads` → `isoft-fm-foundation`.
- **Display name:** `i-Downloads` → `I-Soft File Manager: Foundation`.
- **Main file:** `i-downloads.php` → `isoft-fm-foundation.php`.
- **Text domain:** `'i-downloads'` → `'isoft-fm-foundation'` (~440 strings).
- **Classes:** `IDL_*` → `ISFM_*` (28 classes — `ISFM_Settings`, `ISFM_File_Manager`, `ISFM_Post_Type`, `ISFM_Activator`, etc.).
- **Constants:** `IDL_VERSION` → `ISFM_VERSION`, `IDL_PLUGIN_DIR`/`URL`/`FILE`/`BASENAME` → `ISFM_PLUGIN_DIR`/`URL`/`FILE`/`BASENAME`.
- **Function prefix:** `idl_*` → `isfm_*` (e.g. `idl_get_settings()` → `isfm_get_settings()`, `idl_files_dir()` → `isfm_files_dir()`, `idl_sanitize_filename()` → `isfm_sanitize_filename()`).
- **Post type:** `idl` → `isfm_file`. WP-core hooks that derive from it (`save_post_idl` → `save_post_isfm_file`, `manage_idl_posts_columns` → `manage_isfm_file_posts_columns`, etc.) and template-hierarchy files (`templates/archive-idl.php` → `templates/archive-isfm_file.php`, `templates/single-idl.php` → `templates/single-isfm_file.php`, `templates/taxonomy-idl_category.php` → `templates/taxonomy-isfm_category.php`, `templates/taxonomy-idl_tag.php` → `templates/taxonomy-isfm_tag.php`) renamed accordingly.
- **Taxonomies:** `idl_category` → `isfm_category`, `idl_tag` → `isfm_tag`. Taxonomy hooks like `created_idl_category` → `created_isfm_category` follow.
- **Database tables:** `wp_idl_files` → `wp_isfm_files`, `wp_idl_download_log` → `wp_isfm_download_log`, `wp_idl_download_daily` → `wp_isfm_download_daily`, `wp_idl_licenses` → `wp_isfm_licenses`.
- **Options:** `idl_*` option keys → `isfm_*` (e.g. `idl_default_access_role` → `isfm_default_access_role`).
- **Post meta:** `_idl_*` → `_isfm_*` (e.g. `_idl_access_role` → `_isfm_access_role`).
- **User meta:** `_idl_allowed_categories` → `_isfm_allowed_categories`.
- **Capabilities:** `idl_*` → `isfm_*` (e.g. `idl_view_logs` → `isfm_view_logs`).
- **Hooks (ours):** every `wp_ajax_idl_*`, `admin_post_idl_*`, `update_option_idl_*`, transient prefix `_transient_idl_` → corresponding `isfm_` form. Public action/filter names (`idl_after_download`, `idl_listing_query_args`, etc.) → `isfm_*`.
- **JS globals:** `IDL` → `ISFM`, `IDLTmce` → `ISFMTmce`, `IDLPublic` → `ISFMPublic`, `idlBrokenLinks` → `isfmBrokenLinks`.
- **CSS:** every `.idl-*` class → `.isfm-*`, every `--idl-*` custom property → `--isfm-*` (Theming docs in `readme.txt` and Display settings tab updated accordingly).
- **Block namespace:** `i-downloads/download-list` → `isoft-fm-foundation/download-list` (same for `/download-button` and `/category-grid`). The demo-page generator emits the new namespace.
- **REST namespace:** `i-downloads/v1` → `isoft-fm-foundation/v1`.
- **File storage directory:** `wp-content/uploads/idl-files/` → `wp-content/uploads/isfm-files/`.
- **POT file:** `languages/i-downloads.pot` → `languages/isoft-fm-foundation.pot` (regeneration with `wp i18n make-pot` recommended after merge).
- **Build script (`build.py`):** main-file reference, output zip filename pattern, and zip-root folder all updated to use the new slug. Local clone folder name (`i-downloads/`) stays unchanged — the rename is plugin-identity, not repo-rename.
- **PHPCS config (`phpcs.xml.dist`):** allowed prefix list now `ISFM_` / `isfm_` / `ISFM`.

### Internal-only

- 28 class files renamed in content (autoloader still computes filenames via `str_replace( array( 'ISFM_', '_' ), array( '', '-' ), $class )` so disk filenames stay `class-foo.php` form).

## [0.7.2] — 2026-05-16

Demo content now ships a showcase page so users can see all three embed patterns in one place.

### Added
- **`IDL_Demo_Content::create_demo_page()`** runs after `create_downloads()` and inserts a published WP page (`post_type=page`) titled "i-Downloads — Demo Page" with Gutenberg block markup demonstrating: the Download Entry block (`<!-- wp:i-downloads/download-button {"downloadId":N} /-->`) using the first created download, the Download List block in list mode (`{"layout":"list","limit":6}`), and the Download List block in grid mode (`{"layout":"grid","limit":6}`). Each block sits under a heading + caption explaining what it is. Bilingual content follows the existing `cyrillic_titles` setting.
- **`IDL_Demo_Content::demo_page_content()`** builds the block markup string. All translated content escaped with `esc_html()` before concatenation into the block comments; `downloadId` is cast to `int`.

### Changed
- **`IDL_Demo_Content::create_downloads()`** now returns `list<int>` of created download IDs so the demo page can reference one for the single-download section. Previously returned `void`.
- **`IDL_Demo_Content::remove_demo_posts()`** now queries `post_type => array( 'idl', 'page' )` so the Remove Demo Content button cleans up the new demo page along with the downloads. File cleanup is gated on `get_post_type() === 'idl'` since pages have no associated `idl_files` rows.

## [0.7.1] — 2026-05-16

In-admin theming reference.

### Added
- **"Theming" section on the Display settings tab.** Mirrors the `Customizing appearance` block in `readme.txt` but rendered inside `wp-admin` so it's discoverable before WordPress.org approval (the readme block only renders nicely on the WP.org plugin page). Three collapsible `<details>` blocks: a copy-paste example (open by default), the full table of 18 `--idl-*` CSS variables with defaults and descriptions, and the public BEM class hierarchy. Plus a primary-button link that deep-links to `customize.php?autofocus[section]=custom_css`.
- `.idl-theming-details`, `.idl-theming-snippet`, `.idl-theming-table` styles in `admin/css/admin-style.css` — minimal, scoped to this section.

## [0.7.0] — 2026-05-14

Theming refactor — public stylesheet is now a public API. Replaces the removed Custom CSS textarea (0.6.1) with a WordPress-approved customization path: users override `--idl-*` CSS custom properties from `Appearance → Customize → Additional CSS`. No plugin code accepts user CSS.

### Added
- **CSS custom properties** for every color in `public/css/public-style.css`, defined on `:root` with the `--idl-*` prefix. 18 variables in total covering surfaces (`--idl-card-bg`, `--idl-card-border`, `--idl-row-border`, `--idl-title-band-bg`), typography colors (`--idl-meta-color`, `--idl-empty-color`), the HOT badge (`--idl-badge-hot-bg`, `--idl-badge-hot-color`), and all nine file-type colors (`--idl-icon-pdf-bg` … `--idl-icon-file-bg` plus `--idl-icon-color`). All applied via `var(--idl-*, #fallback)` so the visual default holds even if `:root` is somehow nuked.
- **"Customizing appearance" section in `readme.txt`** between the FAQ and Screenshots, documenting both the 18 CSS variables and the public BEM class hierarchy. Includes a copy-paste snippet showing how to use it from Customizer → Additional CSS.

### Changed
- **File-type colors deduplicated.** Previously the same hex (e.g. `#c0392b` for PDF) was hardcoded twice — once on `.idl-icon--pdf` for the list-mode tile and again on `.idl-grid .idl-meta--type.idl-type--pdf` for the grid-mode badge. Both now resolve through `var(--idl-icon-pdf-bg)` so a single override changes both renderings.
- **Grid-mode meta color consolidated** from `#555` to `#666` so it matches list mode and shares the `--idl-meta-color` variable. Marginal visual shift; users who preferred the darker tone can set `:root { --idl-meta-color: #555; }` in Additional CSS.

### Deliberately excluded
- **No `--idl-btn-bg` / `--idl-btn-color`.** Adding them would either be orphan vars (defined but not applied) or would force a `background:` declaration on `.idl-download-btn` that overrides the theme's `wp-element-button` styling — a visual regression for every existing install. Users wanting button restyling target `.idl-download-btn` directly (documented in the class hierarchy section).
- **No spacing variables** (card padding, grid gap, etc.). Changing those breaks the 1.5:2.7 portrait lock and container-query thresholds. Save for a later release if requested.
- **No admin-side variables.** Admin UI styling isn't a customization surface; users don't customize wp-admin chrome via plugins.

## [0.6.1] — 2026-05-13

WordPress.org plugin review (round 1) fixes.

### Removed
- **Custom CSS feature.** `idl_custom_css` setting, the Advanced-tab textarea, the sanitization map entry, and the `wp_add_inline_style()` enqueue all gone. Reviewer flagged it as arbitrary code injection (Guideline 11 — no custom CSS/JS/PHP). Upgrade path: `IDL_Activator::drop_legacy_columns()` calls `delete_option( 'idl_custom_css' )` so existing rows are removed on activation. Uninstall already wildcards `idl_%`.

### Changed
- **REST permission tightening.** New `IDL_Rest_Api::logs_permission()` callback (`current_user_can( 'idl_view_logs' )`) replaces `editor_permission()` on `/stats/overview` and `/logs`. Both endpoints expose download history; the `idl_view_logs` cap is the same one used by the admin Logs screen.
- **Block render output escaped.** New `idl_allowed_html()` helper in `includes/functions.php` returns a kses allowlist built on `wp_kses_allowed_html('post')` plus `data-*`, `hidden`, `role`, and `aria-*` attributes our card / list / grid templates use. The three block render files (`blocks/download-list/render.php`, `blocks/category-grid/render.php`, `blocks/download-button/render.php`) now wrap their `do_shortcode()` / `ob_get_clean()` output in `wp_kses( $output, idl_allowed_html() )` and drop the previous `phpcs:ignore`.
- **Inline `<style>` moved to enqueued CSS.** `.idl-soon-badge` rules from `admin/views/extensions-tab.php` now live in `admin/css/admin-style.css` (already enqueued by the Settings page).
- **Inline `<script>` replaced with `wp_localize_script`.** TinyMCE modal config (`IDLTmce` global) registered against a data-only script handle (`wp_register_script( 'idl-tinymce-config', false, ... )`) in `IDL_Tinymce::enqueue_assets()`. The TinyMCE plugin (`admin/js/tinymce-plugin.js`, loaded via `mce_external_plugins`) reads `window.IDLTmce` at init time exactly as before.
- **PHP 8.4 chained-constructor syntax rewritten** (`new Class()->method()` → `( new Class() )->method()`) across 14 call sites in 9 files. Reviewer's static analyzer doesn't grok 8.4 syntax even though `Requires PHP: 8.4`. The parenthesized form is parser-compatible back to PHP 7.0 and behaves identically. Files: `i-downloads.php` (bootstrap block), `includes/class-post-type.php`, `includes/class-download-handler.php`, `includes/class-pdf-thumbnail.php`, `includes/class-broken-links-ajax.php`, `includes/class-admin-meta-boxes.php` (6 call sites in this one file alone), `public/views/download-card.php`.
- **`readme.txt` cleanup.** `Contributors:` now `chillic, isoftmionica` (chillic is the WP.org owner account; isoftmionica is the same person under the I-SOFT enterprise). Description no longer references jDownloads by name (defuses the name-confusion concern flagged by the reviewer's AI).

### Documented
- **`wp_ajax_delete-tag` hook** in `IDL_Category_Folders::register_hooks()` now has an explanatory comment: `delete-tag` is WP core's own AJAX action name, not our prefix. The reviewer's tool flagged it as an unprefixed common-word hook (false positive).

### Deferred
- **Plugin name change.** Reviewer's AI flagged "i-Downloads" as not distinctive (single-letter prefix + generic word; pattern similar to jDownloads / JDownloader). We keep the slug for now and will argue with the human reviewer when it surfaces.

## [0.5.2] — 2026-04-18

### Added
- **Query-level RBAC enforcement.** All frontend `idl` queries (shortcodes, archives, taxonomy pages, search) now filter by `_idl_access_role` via a centralized `pre_get_posts` + `posts_clauses` hook in `IDL_Access_Control`. Restricted downloads no longer leak titles, metadata, or file info to unauthorized users. Uses `LEFT JOIN` on postmeta so downloads without `_idl_access_role` meta inherit the global `idl_default_access_role` setting.
- **"Restricted" label** shown to logged-in users who lack the required role — in download cards, `[idl_button]`, `[idl_download]`, and table layout. Previously showed a blank action column.
- **Access check on `[idl_count]`** — returns empty string for restricted downloads to prevent information disclosure.
- **`edit_post` capability check** on REST endpoint `GET /downloads/{id}/files` — admin-facing endpoint now properly gates on WP's mapped capabilities.
- **Password-protection guard** in download handler — `post_password_required()` check blocks direct-URL bypass of password-protected posts.
- **Access Role in Publish box** — replaces WordPress Visibility toggle (Public/Private/Password) with the plugin's own Access Role dropdown. WP Visibility section hidden via CSS for `idl` post type. `post_password` force-stripped on save.
- **Agreement fields moved** from Download Settings to Version & License meta box, grouped with the License picker.

### Removed
- **Download Settings meta box** — emptied after Access Role moved to Publish box and Agreement moved to Version & License. View file `admin/views/meta-box-settings.php` deleted.

### Changed
- **`can_access_download()` fallback** now uses `get_option('idl_default_access_role', 'public')` instead of hardcoded `'public'`.
- **Version Info meta box** renamed to "Version & License" to reflect added Agreement fields.

### Disabled (TODO v1.0)
- `_idl_featured` — will pin downloads to top of category when sort=featured.
- `_idl_external_only` — will prefer external source when download has both local and remote files.
- `_idl_cat_access_role` — will enforce category-level read access in `IDL_Access_Control`.

## [0.5.1] — 2026-04-17

### Fixed
- **Default Access Role** (`idl_default_access_role`) now used as the fallback when rendering and saving the per-download access role meta box. Previously hardcoded to `'public'` in both the display default ([class-admin-meta-boxes.php:112](i-downloads/includes/class-admin-meta-boxes.php#L112)) and the save fallback ([class-admin-meta-boxes.php:155](i-downloads/includes/class-admin-meta-boxes.php#L155)).
- **Download Counting** (`idl_enable_counting`) now gated in `class-download-handler.php`. `increment_count()` is only called when the setting is enabled. Previously the setting was read into `idl_get_settings()` but never checked before counting.
- **Items Per Page** (`idl_items_per_page`) now used as the default `limit` in the `[idl_list]` shortcode. Previously the shortcode hardcoded a default of 10; the setting only affected search results.
- **Custom CSS** (`idl_custom_css`) now enqueued on the frontend via `wp_add_inline_style( 'idl-public', ... )` in `IDL_Shortcodes::enqueue_assets()`. Previously the CSS was saved to the database but never applied to any page.

## [0.5.0] — 2026-04-17

### Added
- **Rate limiting enforcement.** The "Rate Limit (per IP/hour)" setting in Settings → Security now works. Uses per-IP transients (`idl_rl_{hash}`) with `HOUR_IN_SECONDS` TTL. Returns HTTP 429 when exceeded. Fires `idl_rate_limit_exceeded` action with the IP and configured limit for custom logging or ban integration.
- **Hotlink protection enforcement.** The "Block downloads from external referers" checkbox in Settings → Security now checks `HTTP_REFERER` against `home_url()` and blocks mismatches with HTTP 403. Empty referer (direct navigation, privacy extensions) is allowed through — only off-site referers are rejected.
- Registered `idl_block_user_agents` setting placeholder for future user-agent blocklist enforcement.
- Registered `idl_enable_zip_bundle` setting placeholder for planned one-click ZIP bundle of multi-file downloads.
- `hotlink_protection` key added to `idl_get_settings()` return array.

## [0.4.9] — 2026-04-16

### Changed
- **`%i` identifier placeholder** across all custom-table queries in `IDL_File_Manager`, `IDL_License_Manager`, `IDL_Download_Logger`, `IDL_Log_Table`, `IDL_Export`, and `IDL_Rest_Api`. Table names and `ORDER BY` columns now use the WP 6.2+ `%i` placeholder in `$wpdb->prepare()` instead of string interpolation — eliminates every `InterpolatedNotPrepared` and `UnescapedDBParameter` warning without any suppression comment.
- **Admin columns file count** now routes through the cached `IDL_File_Manager::get_files()` instead of a raw `COUNT(*)` query — drops both `DirectQuery` and `NoCaching` warnings and benefits from the object-cache layer added in 0.4.8.
- Added `phpcs:ignore` with rationale to `uninstall.php` wildcard option cleanup (no WP API for wildcard `delete_option()`).

## [0.4.8] — 2026-04-15

### Added
- **Object cache layer** for `IDL_File_Manager` and `IDL_License_Manager`. Hot-path reads (`get_files`, `get_file`, `get_all`, `get`) cache under the `idl_files` / `idl_licenses` groups with `HOUR_IN_SECONDS` TTL. On a 60-item download listing this collapses N+1 file lookups to a single warm-up query plus cache hits. All write paths bust the affected keys via a per-download + per-file key helper; `IDL_File_Manager::bust_cache_for()` is exposed as a public static for external callers (broken-links AJAX, integrity scan, category-folder rename).
- **5-minute transient cache** for the stats dashboard. New `idl_get_stats_overview()` helper in `includes/functions.php` wraps four `COUNT(*)`s + three aggregates behind a single `idl_stats_overview` transient; admin dashboard and REST `stats/overview` share the same cache.
- **`FileManagerCacheTest`** and **`LicenseManagerCacheTest`** — 12 new phpunit tests covering prime, hit, and every write-path bust (`add_external_link`, `update_meta`, `delete_file`, `increment_count`, `update_sort_order`), plus external `bust_cache_for()` invalidation.

### Changed
- **SQL-fragment refactor** in `class-log-table::prepare_items`, `class-export::fetch_rows`, and `class-rest-api::get_logs`. Removed the `$base_sql` / `$where` string-building pattern; each call site now branches on the filter state and passes `$wpdb->prepare()` a single literal SQL string per branch. The `ORDER BY $orderby $order` interpolations stay — `$orderby` is allowlisted and `$order` is hardcoded `ASC|DESC` — but static analysis can now verify the prepare first-arg is a literal. This eliminates every `InterpolatedNotPrepared` and `UnescapedDBParameter` warning on those three files without a single suppression.
- **`IDL_File_Integrity`** — inlined `{$wpdb->prefix}idl_files` at all six query sites (the `$table` local was a readability shortcut that cost a sniff hit per use). `run_scheduled_check` loop body wrapped in a rationale-tagged `phpcs:disable` block.
- **`IDL_Broken_Links_Ajax`** — cache busts centralized in `refresh_inode` and `mark_healthy` (the two chokepoints every handler goes through), plus explicit busts at the reassign sibling-loop and split handler. Class-level `phpcs:disable` with rationale (every `handle_*` calls `$this->guard()` which runs `check_ajax_referer`; the sniff cannot follow the indirection).
- **Structured suppression sweep** — every remaining `phpcs:ignore` across `class-activator`, `class-deactivator`, `class-cron`, `class-download-logger`, `class-category-folders`, `class-admin-meta-boxes`, `class-admin-columns`, `class-download-handler` (the deliberate `wp_redirect` for off-site external links), `class-shortcodes`, `class-tinymce`, `admin/views/settings-page.php`, and `admin/views/log-viewer.php` now carries a rationale a reviewer can verify locally (write-path / cron / activator / one-shot / false-positive / index-backed slow-query hint / read-only display filter).

## [0.4.7] — 2026-04-15

### Added
- **File integrity system.** New `IDL_File_Integrity` class with serve-time and scheduled detection of local files missing from their expected path. Cron hook `idl_integrity_check` runs daily at a configurable time (default 02:30, offset from the 01:00 HOT job). Per-run summary stored in `idl_integrity_last_run` option and surfaced as an admin notice.
- **Inode-based rename recovery.** `IDL_File_Manager::add_local_file()` captures `fileinode()` at upload time. When a file is renamed in place, the scan finds it via stat-loop over the category folder (not a brute-force hash scan), verifies with SHA-256 to guard against inode recycling, and auto-relinks. Gated behind `idl_integrity_use_inode` option (default on, **disable on Windows/NTFS hosting** — non-POSIX filesystems don't expose stable inodes).
- **Broken Links admin screen** at Downloads → Broken Links. `WP_List_Table` subclass with per-row recovery dialog offering cross-category hunt, Move back, Reassign download, Split into new draft, Reupload, Detach. Recovery dialog does one-shot hash verify before committing any move/reassign.
- **Friendly end-user page** (`templates/file-unavailable.php`) replaces the raw `wp_die()` 404 at serve time. Renders with `status_header(503)`, headline "temporarily unavailable", and a "Contact site administrator" mailto button with pre-filled subject and body.
- **Maintenance settings tab** — enable toggle, daily time picker, auto-relink toggle, inode toggle with prominent Windows warning, Run Now button, last-run readout.
- **Auto-republish** on recovery, gated by `_idl_auto_unpublished_at` postmeta so manually-drafted posts are not flipped back to publish by the integrity system.
- **Partial-missing rendering** — in `public/views/download-card.php`, missing files render with `idl-file-item--missing` class (opacity .55, strike-through, no download button) while healthy siblings remain clickable.
- **10 new phpunit tests** under `tests/IntegrityTest.php` covering missing-flag defaults, `handle_missing()` marking + idempotency + conditional unpublish, `try_relink_by_inode()` on renamed files, scan healing previously-missing rows, cron rescheduling on option change, auto-republish flag gating.

### Changed
- `IDL_Download_Handler::serve_local_file()` — when the local file is unreadable, delegates to `IDL_File_Integrity::handle_missing()` + `render_unavailable_page()` instead of `wp_die()`.
- Schema migration adds `is_missing TINYINT(1)`, `missing_since DATETIME`, and `inode BIGINT UNSIGNED` columns to `wp_idl_files`, plus `idx_file_hash` and `idx_is_missing` indexes. `dbDelta()` handles the upgrade idempotently.
- Settings page — new "Maintenance" tab registered alongside existing tabs.
- Broken Links submenu label shows a red badge with the current missing count.

## [0.4.6] — 2026-04-14

### Changed
- **Full WPCS 3.0 pass.** Reduced phpcs violations from 1,575 errors + 399 warnings down to **0 errors + 18 intentional warnings**. Every remaining warning has a rationale-bearing `phpcs:ignore` suppression (direct filesystem ops where `WP_Filesystem` doesn't apply, external `wp_redirect()` for off-site download targets, read-only `$_GET` display filters in admin list tables, reserved-keyword parameter names in autoloaders and filter callbacks, Ghostscript `exec`/`shell_exec` for PDF thumbnails).
- Custom phpcs ruleset (`phpcs.xml.dist`) pinned to WPCS 3.0, PHP 8.4, WP 6.6+. Excludes long-array syntax, docblock sniffs, and template-scope variable globals — keeps short `[]` syntax and the class-file autoloader naming convention while enforcing the rest of WordPress core style.
- `composer.json` now requires PHP `>=8.4` to match the plugin header (was `>=8.1`).

### Added
- **PHPUnit test suite** under `tests/` — 16 tests, 45 assertions, all green on wp-phpunit 6.9 + PHPUnit 9.6.
  - `ActivationTest` — custom tables exist, CPT and taxonomies register, `idl_files_dir()` is under uploads.
  - `FileManagerTest` — add/get/get_files/update_meta/increment_count/delete for external links.
  - `HelpersTest` — `idl_create_draft_download()` title requirement, CPT/status/category/meta wiring; `idl_cyrillic_to_latin()` transliteration; `idl_category_folder_path()` ancestor walking; end-to-end Cyrillic-name → ASCII-folder-path invariant.
- `phpunit.xml.dist` + `tests/bootstrap.php` + `tests/wp-tests-config-sample.php` + `tests/php.ini` + `tests/php-wrapper.bat` (Windows/Local-by-Flywheel harness).

## [0.4.5] — 2026-04-11

### Changed
- Marked Sentinel and Orbit extensions as **Coming soon** in the Extensions tab with an amber badge. Learn More buttons marked `aria-disabled`.

## [0.4.4] — 2026-04-11

### Added
- **Frontend unpublished-download visibility filter.** Drafts and private downloads are now hidden from frontend queries unless the current user's allowed-category set covers them. Implemented via `posts_clauses` for SQL-level OR between `post_status = 'publish'` and "user is in scope" — `WP_Query` can't express this natively.
- **Classic editor category metabox filter.** `get_terms_args` filter scoped to `post.php` / `post-new.php` on `idl` posts injects an `include` list of the user's effective term IDs, so editors only see categories they can actually pick.

## [0.4.3] — 2026-04-11

### Fixed
- External-link downloads silently redirecting to `/wp-admin/`. `wp_safe_redirect()` rejects any URL whose host isn't in WordPress's allowlist and falls back to admin — the opposite of what you want for an intentional off-site link. Switched to `wp_redirect()` with explicit URL validation.

## [0.4.2] — 2026-04-11

### Fixed
- Multi-file grid cards bursting out of their fixed-aspect tile because each file rendered as its own flex row. Cards with a `__title` header (multi-file indicator) now release the 1.5:2.7 aspect lock and stack files compactly, using CSS `:has()` to detect the case.

## [0.4.1] — 2026-04-11

### Fixed
- File-type badge CSS specificity bugs: grid badges rendered grey instead of colored, and list-mode showed duplicate badges next to the big icon tile. `.idl-file-item__meta .idl-meta--type` (2-class) now matches `.idl-file-item__meta .idl-meta` for the hide rule, and type colors use a 3-class selector to beat the fallback.

## [0.4.0] — 2026-04-11

### Changed
- File-type badges on the public download card are now **grid-only**. Single-download views and the Download Button block were showing a duplicate small badge next to the already-colored big icon tile.

## [0.3.9] — 2026-04-11

### Changed
- **Container queries instead of media queries.** The public download list now adapts to whatever container it's dropped into — narrow sidebar, full-width FSE template, two-column layout — independent of viewport. `.idl-list-wrap`, `.idl-grid`, `.idl-category-grid` are query containers; breakpoints moved from `@media` to `@container` with rem-based thresholds so zoom scales them naturally.
- **px → em/rem where lossless.** Grid track minmax (`180px` → `11rem`), list-mode file icon tile (`42px × 52px` → `2.6em × 3.25em`), HOT badge sizing, modal dimensions. Kept px for 1-pixel hairlines, tap-target minimums (WCAG 2.5.5 is in physical px), and dashicon glyph sizing.

## [0.3.8] — 2026-04-11

### Fixed
- Mobile rendering of grid cards — the portrait aspect-ratio was leaving massive whitespace when only one card fit per row. Released the aspect lock on narrow viewports so cards size to their content.

## [0.3.7] — 2026-04-11

### Fixed
- Grid-card title clipping to a single line instead of wrapping. `white-space: nowrap` was cascading from the list-mode title rule; the grid override now explicitly resets `white-space`, `text-overflow`, and puts `-webkit-line-clamp: 3` directly on the title element.

## [0.3.6] — 2026-04-11

### Changed
- Grid-card layout rewritten with three fixed bands: **30% title strip**, **55% meta block**, **15% full-width download button**. Title band gets a distinct light-grey background and bottom divider. Button `padding: 0; line-height: 1` so text actually centers vertically (`.wp-element-button` ships with padding that was pushing text off-center).
- Meta row reflows column-wise in grid mode, `font-size: .95em` (up from `.75em`), dashicons bumped to `18px`.

## [0.3.5] — 2026-04-11

### Changed
- Grid card: title on top with 3-line clamp, file type as a colored inline badge in the meta row, full-width bottom button. Date formatting now falls back to `get_option('date_format')` when the plugin's date format setting is empty.

## [0.3.4] — 2026-04-11

### Changed
- Grid tracks use `repeat(auto-fill, minmax(180px, 1fr))` instead of `repeat(3, 1fr)` so cells never stretch wider than a card when the row is half-empty.

## [0.3.3] — 2026-04-11

### Added
- **"Include subcategories" toggle** on the Download List block (Filter panel) and `[idl_list]` shortcode. Default `true`, matching previous implicit behaviour; can be turned off for "this category exactly" listings.

### Fixed
- Rewrite flush triggered on upgrade fixes `idl_category` taxonomy archive 404s when the slug option is freshly set.

## [0.3.2] — 2026-04-11

### Fixed
- Non-admin users getting locked out of draft save with "you don't have permission to edit this post". `can_edit_download()` returned `false` for posts with no assigned category (fresh auto-drafts), which `map_meta_cap` translated into a `do_not_allow` on `edit_post`. Brand-new drafts now pass through; save-time `tax_input` enforcement still blocks forbidden categories.
- Dropped the save-time "source category" check. By the time `save_post_idl` fires, `wp_insert_post` has already written the new `tax_input` terms, so the "source" read back was actually the target. Source is already enforced by `map_meta_cap` at screen/REST entry, so the save-side check was both broken and redundant.

## [0.3.1] — 2026-04-11

### Added
- `wp_insert_post_data` filter transliterates Cyrillic characters in `idl` post slugs to Latin. Same urldecode-first pattern as the category fix.

### Fixed
- Dropzone click not opening the file picker. `jQuery.trigger('click')` fires a synthetic event — browsers only open the file-picker dialog in response to a real user gesture, which means the native DOM `element.click()` method. Called that directly instead.

## [0.3.0] — 2026-04-11

### Added
- **User-category ACL (write-side permissions).** New `IDL_Category_ACL` class:
  - `_idl_allowed_categories` user meta — array of explicit term IDs.
  - `get_effective()` expands each explicit ID with `get_term_children()` so inheritance works down the subtree.
  - `map_meta_cap` filter on `edit_post` / `delete_post` / `publish_post` denies via `do_not_allow` when the user can't write the download's category. Covers admin UI, REST, inline row actions, quick edit, bulk edit, and all AJAX handlers gated on `current_user_can('edit_post', $id)`.
  - `save_post_idl` priority 1 rejects the save with `wp_die(403)` if posted `tax_input[idl_category]` contains a forbidden term.
  - `pre_get_posts` admin list filter injects a `tax_query` restricting to effective categories. Empty set → shows nothing.
  - Profile UI with collapsible `<details>` category tree (zero JS), admin-only. Branches auto-open when a descendant is selected.
  - Admins (`manage_options`) always unrestricted.

## [0.2.9] — 2026-04-10

### Added
- **Per-file inline metadata editing.** Edit button on each file row opens an inline editor for title + description. `IDL_File_Manager::update_meta()` writes only those two fields; file path / name / size / hash / mime are never touched. Optimistic row update on save.

## [0.2.8] — 2026-04-10

### Removed (Dead-weight purge)
- `wp_idl_files.attachment_id` column dropped via activator migration (`drop_legacy_columns()`, idempotent via `INFORMATION_SCHEMA` check).
- `IDL_File_Manager::attach_media()` method.
- `wp_ajax_idl_attach_media` handler.
- Media-library fallback in `IDL_Download_Handler::resolve_path()`.
- `get_attached_file($file->attachment_id)` in `IDL_Pdf_Thumbnail` — now reads from `file_path`.
- `idl_storage_mode` and `idl_custom_folder` options.
- `_idl_storage_mode` post meta (cleaned up across all posts).
- `idl_attach_file()` and `idl_file_exists_by_hash()` wrapper functions from `functions.php`.
- Storage-mode dropdowns from `settings-page.php` and `meta-box-settings.php`.
- `wp_enqueue_media()` call.

### Changed
- Settings → General now shows a read-only "Storage Location" display with the absolute `idl_files_dir()` path.

## [0.2.7] — 2026-04-10

### Fixed
- Cyrillic category slug transliteration not triggering. `sanitize_title()` URL-encodes non-ASCII characters, so by the time `force_latin_slug()` checked `preg_match('/\p{Cyrillic}/u', …)`, the string was already `%d0%bf…` — pure ASCII, no Cyrillic to match. Fix: `urldecode()` the stored slug before testing and transliterating.

## [0.2.6] — 2026-04-10

### Changed
- `created_idl_category` / `edited_idl_category` hooks register at `PHP_INT_MAX` priority so no later callback can overwrite the slug after we rewrite it.
- Added `error_log()` diagnostics inside `force_latin_slug()` for debugging.

## [0.2.5] — 2026-04-10

### Added
- `pre_term_slug` filter — intercepts and transliterates inside `wp_insert_term()` before the slug is stored. Runs alongside the `created_idl_category` DB safety net.

## [0.2.4] — 2026-04-10

### Added
- `IDL_Category_Folders::force_latin_slug()` — bulletproof DB-level slug rewrite. Reads via `$wpdb`, transliterates, updates via `$wpdb->update()`, invalidates term cache. Handles uniqueness by appending `-2`, `-3`, etc. on collision. Unconditional (removes dependency on filter chains being intact).

### Removed
- `wp_insert_term_data` / `wp_update_term_data` filters — unreliable, replaced by the DB-level approach above.

## [0.2.3] — 2026-04-10

### Added
- **Upload popup rewrite.** Files meta box replaced with tabbed UI:
  - **Upload** — drag-and-drop dropzone with per-file XHR upload and progress bars. Native `click()` on the hidden file input. Files land directly in the target category folder via `idl_sanitize_filename()` + collision check.
  - **From Folder** — lists physical files currently in the category folder, flags tracked vs untracked, one-click link import.
  - **External URL** — the pre-existing URL form, preserved.
- `IDL_File_Manager::add_local_file()` — inserts a DB row for a file already on disk.
- Three new AJAX handlers: `ajax_upload_file`, `ajax_browse_category`, `ajax_import_file`.
- Target-category bar at the top of the meta box; warning state when no category is assigned or the post is unsaved.

### Changed
- Category filter on the admin Downloads list now uses `include_children` explicitly (true by default).

### Removed
- Dependency on `wp_enqueue_media()` and the old Media Library picker flow.

## [0.2.2] — 2026-04-10

### Changed
- PHP requirement bumped to **8.4**. Adopted new-without-parens syntax (`new IDL_X()->register_hooks()` instead of `(new IDL_X())->register_hooks()`) throughout the bootstrap.
- `define('IDL_VERSION', …)` replaced with `const IDL_VERSION` where the IDE suggested it.
- Various string interpolation and arrow-function hygiene fixes from IDE hints.

## [0.2.1] — 2026-04-10

### Added
- **Custom folder + category filesystem architecture.** First cut. Every `idl_category` term maps to a physical folder under `wp-content/uploads/idl-files/`, nested by slug chain.
- `idl_files_dir()` helper with static cache.
- `idl_category_folder_path(int)` and `idl_category_fs_path(int)` helpers that walk the ancestor chain.
- **`IDL_Category_Folders` class**:
  - Folder creation on `created_idl_category`.
  - Folder rename on `edited_idl_category` (with prefix-replace SQL updating all affected `idl_files.file_path` rows in one query).
  - Blocking error on category delete if any downloads are still assigned.
  - Auto-move files on disk when a download's category assignment changes (`set_object_terms` hook).
- **Filename sanitization pipeline** (`idl_sanitize_filename()`):
  - Strip duplicate extension (`file.pdf.pdf` → `file.pdf`).
  - Lowercase extension against allow-list from settings.
  - Serbian Cyrillic → Latin transliteration.
  - `remove_accents()` for Latin diacritics.
  - Slugify to `[a-z0-9-]`.
  - 80-char stem length cap, blocking error above.
- `idl_filename_collision()` blocking-collision check.
- `idl_cyrillic_to_latin()` and `idl_latin_to_cyrillic()` transliteration maps (digraph-aware).
- `idl_autofill_title()` — Latin → Cyrillic conversion for the title field when the "Cyrillic titles" setting is on.
- `.htaccess` deny-all written to `idl-files/` on activation (Apache; Nginx handled via settings hint).
- Auto-upgrade on version mismatch via `plugins_loaded` priority-1 version check that calls `IDL_Activator::activate()`.
- Settings → General: **Allowed File Extensions** textarea, **Cyrillic Titles** switch.
- Single-download page template now reads `idl_files_dir()` with `realpath` path-traversal guard.

### Changed
- `IDL_Download_Handler::resolve_path()` now uses `idl_files_dir()` as the single source of truth.
- `plugins_loaded` bootstrap registers `IDL_Category_Folders`.

### Fixed
- Single download page 404 from the activation-hook `flush_rewrite_rules()` firing before `plugins_loaded` (CPT not yet registered). Deferred flush flag pattern: `idl_flush_rewrite_rules` option set on activation, consumed at `init` priority 999.
- `idl_mime_icon_class()` fatal redeclaration — moved from the inline `download-card.php` template (which was included once per download in a list) into `functions.php`.
- Download Button block rendered as a bare button instead of a full card — now loads `get_post()` and renders `download-card.php`.
- Live-search insert UI on the Download Entry block, with debounced `apiFetch`.
- Global **Default Button Text** setting in Settings → Display.
- Classic editor TinyMCE insert button with modal search.

[0.4.5]: https://github.com/isoft-mionica/i-downloads/releases/tag/v0.4.5
[0.4.4]: https://github.com/isoft-mionica/i-downloads/releases/tag/v0.4.4
[0.4.3]: https://github.com/isoft-mionica/i-downloads/releases/tag/v0.4.3
[0.4.2]: https://github.com/isoft-mionica/i-downloads/releases/tag/v0.4.2
[0.4.1]: https://github.com/isoft-mionica/i-downloads/releases/tag/v0.4.1
[0.4.0]: https://github.com/isoft-mionica/i-downloads/releases/tag/v0.4.0
[0.3.9]: https://github.com/isoft-mionica/i-downloads/releases/tag/v0.3.9
[0.3.8]: https://github.com/isoft-mionica/i-downloads/releases/tag/v0.3.8
[0.3.7]: https://github.com/isoft-mionica/i-downloads/releases/tag/v0.3.7
[0.3.6]: https://github.com/isoft-mionica/i-downloads/releases/tag/v0.3.6
[0.3.5]: https://github.com/isoft-mionica/i-downloads/releases/tag/v0.3.5
[0.3.4]: https://github.com/isoft-mionica/i-downloads/releases/tag/v0.3.4
[0.3.3]: https://github.com/isoft-mionica/i-downloads/releases/tag/v0.3.3
[0.3.2]: https://github.com/isoft-mionica/i-downloads/releases/tag/v0.3.2
[0.3.1]: https://github.com/isoft-mionica/i-downloads/releases/tag/v0.3.1
[0.3.0]: https://github.com/isoft-mionica/i-downloads/releases/tag/v0.3.0
[0.2.9]: https://github.com/isoft-mionica/i-downloads/releases/tag/v0.2.9
[0.2.8]: https://github.com/isoft-mionica/i-downloads/releases/tag/v0.2.8
[0.2.7]: https://github.com/isoft-mionica/i-downloads/releases/tag/v0.2.7
[0.2.6]: https://github.com/isoft-mionica/i-downloads/releases/tag/v0.2.6
[0.2.5]: https://github.com/isoft-mionica/i-downloads/releases/tag/v0.2.5
[0.2.4]: https://github.com/isoft-mionica/i-downloads/releases/tag/v0.2.4
[0.2.3]: https://github.com/isoft-mionica/i-downloads/releases/tag/v0.2.3
[0.2.2]: https://github.com/isoft-mionica/i-downloads/releases/tag/v0.2.2
[0.2.1]: https://github.com/isoft-mionica/i-downloads/releases/tag/v0.2.1
