=== I-Soft File Manager: Foundation ===
Contributors: chillic, isoftmionica
Tags: downloads, file manager, document management, categories, download counter
Requires at least: 6.7
Tested up to: 7.0
Requires PHP: 8.4
Stable tag: 0.8.1
License: GPL v2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Hierarchical file download manager. Category-as-folder storage, multi-file entries, per-user ACL, and audit logging.

== Description ==

**I-Soft File Manager: Foundation** is a modular download manager built for modern WordPress, designed for organizations with many documents, a structured category tree, and editorial teams that need per-department write permissions.

= Key features =

* **Category-as-folder storage.** Every `isfm_category` term maps 1:1 to a physical folder under `wp-content/uploads/isfm-files/`. Folder names mirror the category slug chain (`skupstina-opstine/saziv-2025-2029/iv-sednica/`). Rename a category slug and the folder renames on disk plus every affected file path updates in the database.
* **Drag-and-drop upload.** Multi-file dropzone inside the download edit screen, with per-file progress bars. Files land directly in the target category folder.
* **"From Folder" browser.** Files dropped into a category folder via SFTP, rclone, or any other external tool show up as untracked candidates in the admin. One click links them to a download.
* **Per-user category ACL.** Assign each editor a set of allowed categories. They inherit access to the whole subtree. Admins are unrestricted. Non-allowed categories are hidden from their admin list, edit screens, and category picker.
* **Unpublished visibility control.** Draft and private downloads are invisible to users whose allowed-category set doesn't cover them — even to logged-in editors from other departments.
* **Secure download handler.** Files live under an `.htaccess`-protected directory. All downloads route through a PHP handler with nonce verification, access-role checks, rate limiting, hotlink protection, and X-Sendfile / X-Accel-Redirect support.
* **Audit logging.** Optional per-download log with timestamp, file, user, IP (detailed logging), user agent, and referer. Configurable retention.
* **Gutenberg blocks.** Download List (with layout, filter, search, and subcategory toggle), Download Entry (embed a single download card), and Category Grid.
* **Classic editor shortcodes.** `[isfm_list]`, `[isfm_download id="123"]`, `[isfm_categories]`, `[isfm_search]`.
* **Cyrillic filename and slug handling.** Automatic Serbian Cyrillic → Latin transliteration for category slugs, post slugs, and uploaded filenames (configurable extension allow-list, double-extension strip, 80-char cap).
* **License management.** Assign licenses per download, with optional "require agreement before download" modal.
* **PDF thumbnails.** Auto-generate post thumbnails from the first page of a PDF (requires the Imagick PHP extension).
* **REST API.** Every taxonomy and the CPT are exposed to `wp/v2` with custom endpoints for listing and searching.
* **Statistics dashboard.** Per-file and per-download counts, with a nightly HOT recalculation of the top downloads.

= Architecture =

I-Soft File Manager: Foundation stores files outside the Media Library in a predictable per-category folder tree. This is the key difference from most download managers: the filesystem **is** the source of truth for what's stored where. Moving a download to a different category auto-moves its files on disk; deleting a category blocks if any downloads still reference it.

This design exists so automation tools can sync files in and out without having to understand WordPress internals. Rclone mirroring a cloud folder, an SFTP drop from a government mainframe, or a scheduled scan — all just write to `isfm-files/<slug-path>/` and the plugin picks them up.

= Extensions (coming soon) =

* **I-Soft File Manager: Foundation Sentinel** — server-side automation. Monitors category folders for new files, creates draft download entries, and supports rclone mirroring, SFTP bulk upload, and WP-cron folder scans.
* **I-Soft File Manager: Foundation Orbit** — Google Shared Drive sync. Departments drop files into shared folders; Orbit imports them as drafts for review.

== Installation ==

1. Upload the `isoft-fm-foundation` folder to `/wp-content/plugins/`, or install via **Plugins → Add New → Upload Plugin**.
2. Activate through the **Plugins** menu.
3. Visit **I-Soft File Manager: Foundation → Settings** to configure storage, access roles, PDF thumbnails, and log retention.
4. Create at least one **Download Category** — this is required before any file can be uploaded. Categories map directly to folder names under `wp-content/uploads/isfm-files/`.
5. Assign allowed categories to each editor on their **Users → Edit User** profile screen, under **I-Soft File Manager: Foundation — Allowed Categories**. Leave empty for no access. Administrators are always unrestricted.

= Server requirements =

* PHP 8.4 or higher
* WordPress 6.6 or higher
* MySQL 5.7+ or MariaDB 10.3+
* Apache with `mod_rewrite` + `mod_authz_core` **or** Nginx (see Settings → Security for configuration snippets)
* For PDF thumbnails: Imagick PHP extension

== Frequently Asked Questions ==

= Why not just use the Media Library? =

The Media Library flattens everything into `wp-content/uploads/YYYY/MM/`, which breaks predictable automation and makes category-based permissions impossible. I-Soft File Manager: Foundation keeps files organized by category, which is what governments, libraries, and municipalities actually need when they're managing thousands of documents.

= Can I migrate from jDownloads? =

A migration tool is planned but not yet shipped. The data model is intentionally close to jDownloads to make this possible.

= How do I restrict a user to only some categories? =

Open their user profile (**Users → Edit User**), scroll to **I-Soft File Manager: Foundation — Allowed Categories**, check the boxes. Users inherit access to every descendant of a checked category. Administrators bypass all ACL checks.

= What happens to downloads if I delete a category? =

Deletion is blocked if any download is still assigned to that category — the files would otherwise be orphaned. Reassign or delete the downloads first, then delete the category.

= What file types are allowed? =

Configured under **Settings → General → Allowed File Extensions**. Default list covers common document, archive, image, audio, and video formats. Executables (`.exe`, `.sh`, `.bat`) are **not** in the default list and should only be added if you know what you're doing.

= Does it support Cyrillic / non-Latin filenames? =

Yes. Uploaded filenames and category slugs are automatically transliterated from Serbian Cyrillic to Latin for disk storage. Display titles retain the original characters. A setting under **Settings → General → Cyrillic Titles** can auto-fill download titles in Cyrillic from uploaded Latin filenames.

= Does it work with FSE (block) themes? =

Yes. The plugin detects FSE themes and injects the download card via `the_content` filter. Classic theme templates under `templates/` are used as a fallback.

== Customizing appearance ==

I-Soft File Manager: Foundation exposes its styling via CSS custom properties on `:root` so you can recolor cards from **Appearance → Customize → Additional CSS** without writing any selectors.

Example — recolor the PDF icon to match your theme blue and soften card borders:

`:root {
    --isfm-icon-pdf-bg: #1a73e8;
    --isfm-card-border: #ddd;
}`

= Available CSS variables =

* `--isfm-card-bg` — Card background
* `--isfm-card-border` — Card and grid borders
* `--isfm-row-border` — Per-file row separator
* `--isfm-title-band-bg` — Grid-mode title band background
* `--isfm-meta-color` — Date / size / count text
* `--isfm-empty-color` — "No files available" text
* `--isfm-badge-hot-bg` — HOT badge background
* `--isfm-badge-hot-color` — HOT badge text
* `--isfm-icon-color` — File-type icon/badge text
* `--isfm-icon-pdf-bg` — PDF file color
* `--isfm-icon-doc-bg` — DOC / DOCX color
* `--isfm-icon-xls-bg` — XLS / XLSX color
* `--isfm-icon-ppt-bg` — PPT / PPTX color
* `--isfm-icon-zip-bg` — Archive (ZIP / RAR / 7Z) color
* `--isfm-icon-img-bg` — Image color
* `--isfm-icon-vid-bg` — Video color
* `--isfm-icon-aud-bg` — Audio color
* `--isfm-icon-file-bg` — Generic / unknown file color

= Targeting individual classes =

For deeper changes (layout, spacing, typography), all public classes use the `.isfm-` prefix with BEM naming. Key entry points:

* `.isfm-download-card` — Outer wrapper around one download
* `.isfm-download-card__title` — Multi-file card heading
* `.isfm-file-item` — Per-file row
* `.isfm-file-item__icon` — Large file-type tile (list mode only)
* `.isfm-file-item__title` — File or download title link
* `.isfm-file-item__meta` — Date / size / count meta block
* `.isfm-file-item__action` — Action column (button or status label)
* `.isfm-download-btn` — The action button (intentionally not theme-locked via CSS variables; targets WP `wp-element-button` so theme styling stays in control)
* `.isfm-meta--type` — Inline file-type badge (grid mode only)
* `.isfm-badge--hot` — HOT marker
* `.isfm-grid` — Grid wrapper (use `.isfm-grid--cols-3` etc. for per-column-count overrides)
* `.isfm-list-wrap` — List wrapper
* `.isfm-category-grid` — Category grid wrapper

== Screenshots ==

1. Download edit screen with drag-and-drop upload, per-file progress, and the From Folder browser.
2. Download list block in grid mode — portrait tiles with file-type badges.
3. Per-user category ACL tree on the profile screen.
4. Statistics dashboard with HOT downloads and per-file counts.
5. Download handler settings — security, logging, and serve method.

== Changelog ==

= 0.8.1 =
* **Compatibility fixes flagged by Plugin Check after WordPress 7.0 release.** Bumped "Tested up to" to 7.0 and "Requires at least" to 6.7 (the version that introduced `register_block_template()`, which the single-download block template uses). Recreated the empty `languages/` directory the `Domain Path` header points to.
* Suppressed `WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound` warnings in view, template, block-render, and uninstall files — these "globals" are actually local variables passed by the including class. Added a single `phpcs:disable` line per file with a `--` reason.

= 0.8.0 =
* **Plugin renamed to "I-Soft File Manager: Foundation"** (slug `isoft-fm-foundation`), per the WordPress.org plugin-review team's accepted name. Internal identifiers (classes `ISFM_*`, functions `isfm_*`, post type `isfm_file`, taxonomies `isfm_category`/`isfm_tag`, database tables `wp_isfm_*`, options/meta/capabilities `isfm_*`, CSS classes `.isfm-*` and `--isfm-*` custom properties, block namespace `isoft-fm-foundation/*`, REST namespace `isoft-fm-foundation/v1`, file storage dir `isfm-files/`) all renamed to match. Since the plugin had not yet shipped publicly, no migration path is needed.

= 0.7.2 =
* **Demo content now creates a showcase page.** Installing demo content also adds a published WP page titled "I-Soft File Manager: Foundation — Demo Page" with three sections demonstrating the Download Entry block (single download), Download List block in list mode, and Download List block in grid mode — so new users can see all three embed patterns side-by-side without manually composing a page. Removed cleanly by the existing Remove Demo Content button.

= 0.7.1 =
* **Theming docs surfaced in admin.** The Display tab now includes a "Theming" section listing all 18 CSS variables, the public BEM class hierarchy, a copy-paste snippet, and a one-click link to Customizer → Additional CSS. Same content as the readme; previously only visible on the WordPress.org plugin page.

= 0.7.0 =
* **Theming via CSS custom properties.** Every color in the public stylesheet is now exposed as a `--isfm-*` variable on `:root`. Override one variable in **Appearance → Customize → Additional CSS** to recolor any element — no selector knowledge needed. Replaces the removed Custom CSS feature with a WordPress-approved customization path.
* **New "Customizing appearance" section** in this readme listing all 18 variables and the public BEM class hierarchy.
* **File-type colors deduplicated** — the same color now drives both the list-mode tile and the grid-mode badge for each file type.
* Grid-mode meta text consolidated from `#555` to `#666` (matches list mode). Override with `--isfm-meta-color` if you preferred the darker tone.

= 0.6.1 =
* **Removed Custom CSS textarea** from Settings → Advanced. Arbitrary CSS injection is disallowed by WordPress.org plugin guidelines. Existing `isfm_custom_css` option rows are deleted on upgrade.
* **REST hardening.** `/isoft-fm-foundation/v1/stats/overview` and `/isoft-fm-foundation/v1/logs` now require the `isfm_view_logs` capability instead of the broader `edit_posts`.
* **Block render escaping.** All three block render callbacks (Download List, Download Entry, Category Grid) now run their HTML through `wp_kses()` with a plugin-specific allowlist.
* **Inline assets enqueued.** Moved the inline `<style>` block on Settings → Extensions into the admin stylesheet, and replaced the inline `<script>` config in the TinyMCE modal with `wp_localize_script()`.
* **PHP 8.4 chained constructor calls** rewritten to `( new Class() )->method()` form across 14 sites in 9 files. Functionally identical; satisfies older static analyzers used in plugin review.

= 0.6.0 =
* **WPCS compliance.** Full WordPress Coding Standards pass — phpcbf auto-fixes plus manual remediation. Zero errors, zero warnings against `phpcs --standard=WordPress`.
* **i18n audit.** All 440 translatable strings verified; regenerated POT file. Unified duplicate translator comments.
* **Removed Ghostscript backend** from PDF thumbnail generation. Imagick-only — eliminates `exec()`/`shell_exec()` calls flagged by WPCS.
* **Updated phpcs.xml.dist** with project-specific rule exclusions and prefix configuration.

= 0.5.3 =
* **Plugin Check compliance.** Added phpcs suppression comments with rationale for all WordPress Plugin Check warnings (DirectQuery, SlowDBQuery, NonceVerification, InterpolatedNotPrepared). Fixed unescaped output in category deletion guard.

= 0.5.1 =
* **Fixed: Default Access Role** now applies to new downloads. Previously all new downloads defaulted to "Public" regardless of the setting.
* **Fixed: Download Counting** toggle now works. Previously downloads were counted unconditionally even when the setting was disabled.
* **Fixed: Items Per Page** setting now used as the default for `[isfm_list]` shortcode. Previously hardcoded to 10.
* **Fixed: Custom CSS** is now enqueued on the frontend via `wp_add_inline_style()`. Previously the CSS was saved but never applied.

= 0.5.0 =
* **Rate limiting enforced.** The "Rate Limit (per IP/hour)" setting in Security now actually works — uses per-IP transients with 1-hour TTL. Returns HTTP 429 when exceeded. Fires `isfm_rate_limit_exceeded` action for custom logging.
* **Hotlink protection enforced.** The "Block downloads from external referers" checkbox now checks HTTP_REFERER against `home_url()` and blocks mismatches with HTTP 403.
* Registered `isfm_block_user_agents` and `isfm_enable_zip_bundle` settings for future versions (user-agent blocklist; one-click ZIP bundle for multi-file downloads).

= 0.4.9 =
* **`%i` identifier placeholder** across all custom-table queries. Table names and ORDER BY columns now use WP 6.2+ `%i` in `$wpdb->prepare()` instead of string interpolation — eliminates every `InterpolatedNotPrepared` and `UnescapedDBParameter` warning without suppression.
* Admin columns file count now routes through the cached `ISFM_File_Manager::get_files()` — drops both `DirectQuery` and `NoCaching` warnings on the download list screen.

= 0.4.8 =
* **Object cache layer** for `ISFM_File_Manager` and `ISFM_License_Manager`. Hot-path reads (`get_files`, `get_file`, `get_all`, `get`) now cache under the `isfm_files` / `isfm_licenses` groups with `HOUR_IN_SECONDS` TTL. On a 60-item download listing this collapses N+1 file lookups to a single warm-up query plus cache hits. All write paths bust the affected keys; `ISFM_File_Manager::bust_cache_for()` is exposed for external callers (broken-links AJAX, integrity scan, category-folder rename).
* **5-minute transient cache** for the stats dashboard. New `isfm_get_stats_overview()` helper wraps the four COUNT(*) + three aggregate queries behind a single `isfm_stats_overview` transient; both the admin dashboard and the REST `stats/overview` endpoint share the same cache.
* **SQL-fragment refactor** in `class-log-table`, `class-export`, and `class-rest-api::get_logs`. Removed the `$base_sql` / `$where` string-building pattern; each call site now hands `$wpdb->prepare()` a single literal SQL string per branch, so static analysis can verify it. This kills every `InterpolatedNotPrepared` and `UnescapedDBParameter` warning on those three files with zero suppressions.
* **Structured suppression sweep** across the remaining Plugin Check warnings: every `phpcs:ignore` now carries a rationale-tagged comment a reviewer can verify locally (write-path / cron / activator / one-shot / false-positive), not a generic "custom table" excuse.
* 12 new phpunit tests (`FileManagerCacheTest`, `LicenseManagerCacheTest`) covering cache-hit, cache-bust on every write path, and external `bust_cache_for()` invalidation.

= 0.4.7 =
* **File integrity & broken-link recovery.** New scheduled check detects files missing from disk (configurable daily time). Serve-time detection replaces the raw 404 with a friendly "temporarily unavailable" page and a Contact admin button.
* **Inode-based rename recovery** (Linux/POSIX). When a file is renamed in place the scan finds it via stored inode + hash verify and auto-relinks. Toggle in Maintenance settings — disable on Windows hosting.
* **Broken Links admin screen** (Downloads → Broken Links) with per-row recovery: Move back, Reassign download to new category, Split into new draft, Reupload, Detach. Cross-category hunt finds files moved anywhere under the downloads folder.
* Partial-missing downloads stay published with missing files rendered strike-through; fully-missing downloads auto-unpublish and auto-republish on recovery.
* New Maintenance settings tab with enable toggle, daily time picker, auto-relink + inode options, and Run Now button.
* 10 new phpunit tests covering missing-flag detection, idempotent notices, inode relink, auto-republish guard.

= 0.4.6 =
* Full WordPress Coding Standards (WPCS 3.0) pass: 0 errors, 18 intentional warnings (all with rationale suppressions).
* PHPUnit test suite added under `tests/` covering activation, file manager CRUD, helpers, and the Cyrillic-slug → ASCII-folder pipeline.
* Composer requires PHP 8.4 to match the plugin header.

= 0.4.5 =
* Marked Sentinel and Orbit extensions as "Coming soon" in the Extensions tab.

= 0.4.4 =
* Frontend query filter hides unpublished downloads from users whose allowed-category set doesn't cover them (via `posts_clauses` for SQL-level OR).
* Classic editor category metabox filters `get_terms_args` to hide forbidden terms from the picker.

= 0.4.3 =
* Fixed external-link downloads silently redirecting to `wp-admin` — `wp_safe_redirect()` rejects off-site URLs; switched to `wp_redirect()` with explicit URL validation.

= 0.4.2 =
* Multi-file grid cards (detected via `:has(.isfm-download-card__title)`) release the portrait aspect lock and stack files compactly instead of bursting out of a fixed-height tile.

= 0.4.1 =
* Fixed CSS specificity bug: grid-mode file-type badges rendered grey instead of colored, and list-mode showed duplicate badges.

= 0.4.0 =
* File-type badges on the public card are now grid-only, removing duplication on single-download views and the Download Button block.

= 0.3.9 =
* Converted media queries to container queries — the download list now adapts to whatever container it's dropped into (sidebar, full-width, two-column), not the viewport.
* Most pixel values converted to rem/em where lossless. Kept px for hairlines, tap targets, and dashicon glyph sizing.

= 0.3.8 =
* Mobile: released the portrait aspect lock on narrow viewports so cards size to their content.

= 0.3.7 =
* Fixed grid-card title clipping instead of wrapping (cascaded `white-space: nowrap` from list mode).

= 0.3.6 =
* Grid-card layout rewritten with three fixed bands: 30% title strip, 55% meta block, 15% full-width download button.

= 0.3.5 =
* Grid card rework: file type as a colored inline badge, title on top with 3-line clamp, full-width bottom button.

= 0.3.4 =
* Grid tracks switched to `auto-fill minmax` so cells never stretch wider than a card.

= 0.3.3 =
* Added "Include subcategories" toggle to the Download List block and `[isfm_list]` shortcode.
* Rewrite flush on upgrade fixes `isfm_category` taxonomy archive 404s.

= 0.3.2 =
* Fixed category ACL lockout on draft save — `can_edit_download()` now permits edits on posts with no assigned category, relying on save-time target enforcement instead.
* Dropped the broken save-time source-category check (source is already enforced by `map_meta_cap` at screen entry).

= 0.3.1 =
* Transliterate Cyrillic in `isfm_file` post slugs to Latin (post slug filter, same pattern as category fix).
* Fixed dropzone click not opening the file picker — `jQuery.trigger('click')` fires a synthetic event; browsers require a native `click()` for file dialogs.

= 0.3.0 =
* **User-category ACL** — per-user `_isfm_allowed_categories` meta with subtree inheritance. `map_meta_cap` gate on edit/delete/publish. `save_post_isfm_file` target enforcement. Admin list filter. Collapsible category tree on user profile screen. Admins always unrestricted.

= 0.2.9 =
* **Per-file inline metadata editing** — Edit button on each file row opens an inline editor for title and description.

= 0.2.8 =
* **Dead-weight purge**: dropped `attachment_id` column via activator migration, removed `attach_media()` + `ajax_attach_media` handler, media-library fallback in download handler + PDF thumbnail, `isfm_storage_mode` / `isfm_custom_folder` options, `_isfm_storage_mode` postmeta, `wp_enqueue_media()` call.

= 0.2.7 =
* Fixed Cyrillic category slug transliteration — `sanitize_title()` URL-encodes non-ASCII so `force_latin_slug()` needs to `urldecode()` first before testing for Cyrillic.

= 0.2.6 =
* `created_isfm_category` / `edited_isfm_category` hooks bumped to `PHP_INT_MAX` priority so no later callback can overwrite the rewritten slug.

= 0.2.5 =
* `pre_term_slug` filter to transliterate Cyrillic at slug-generation time.

= 0.2.4 =
* `force_latin_slug()` DB-level rewrite with uniqueness handling.

= 0.2.3 =
* **Upload popup rewrite**: Upload / From Folder / External URL tabs. Drag-drop dropzone with per-file XHR progress. Untracked filesystem browser with one-click link.
* New `ISFM_File_Manager::add_local_file()`.
* Dropped `wp_enqueue_media()` dependency.

= 0.2.2 =
* PHP 8.4 required. Cleaned up parenthesized `(new X())->` instantiations now that `new X()->` is valid syntax.
* `const ISFM_VERSION` instead of `define()`.

= 0.2.1 =
* **Custom folder + category filesystem architecture** replacing media-library mode.
* `isfm-files/` base directory with `.htaccess` deny-all.
* Category folders auto-materialize from slug chains. Slug edits rename folders on disk and prefix-replace affected `file_path` rows.
* Filename sanitization pipeline: extension allow-list, double-extension strip, Cyrillic transliteration, slugify, 80-char cap.
* Category delete blocks if any download is still assigned.
* Auto-move files when a download's category changes (`set_object_terms` hook).
* Download handler serves from `isfm_files_dir()` with `realpath` traversal guard.
* `isfm_allowed_extensions` and `isfm_cyrillic_titles` settings.

== Upgrade Notice ==

= 0.4.6 =
Code-quality and test-coverage release. No behavior changes, no migration steps. Requires PHP 8.4.

= 0.4.0 =
This release completes the custom-folder architecture and adds per-user category ACL. Review your editors' allowed-category assignments under **Users → Edit User** after upgrading. Requires PHP 8.4.
