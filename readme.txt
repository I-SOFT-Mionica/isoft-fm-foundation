=== I-Soft File Manager: Foundation ===
Contributors: chillic
Tags: downloads, file manager, document management, categories, download counter
Requires at least: 6.7
Tested up to: 7.0
Requires PHP: 8.4
Stable tag: 0.10.19
License: GPL v2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Document and download manager with real on-disk folder organization, per-department access control, and multi-file downloads.

== Description ==

**I-Soft File Manager: Foundation** is a modular download manager built for modern WordPress.

Unlike standard media plugins that dump every upload into a single dated folder, Foundation is designed for municipalities, universities, libraries, and any team that needs to manage thousands of public documents, maintain a strict category tree, and give different departments their own secure upload spaces.

= Key features =

* **True folder organization.** Every category you create maps 1:1 to a real folder on disk under `wp-content/uploads/isoft-fmf-files/`. Rename a category and the folder renames itself; every file path in the database updates instantly.
* **Department-level access control.** Restrict editors to specific categories so HR uploads only land in the HR folder, IT uploads only in the IT folder, and so on. Restricted users cannot see drafts from other departments either.
* **External file sync and "From Folder" browser.** Because categories are real folders, drop files into them via SFTP, rclone, or any external sync tool. The From Folder browser detects untracked files instantly so you can link them to a download with one click.
* **One-click multi-file downloads.** Upload multiple files to a single entry via drag-and-drop. Visitors can grab individual files or everything at once as an automatically generated ZIP bundle.
* **Built-in analytics and audit log.** Statistics dashboard tracks per-file and aggregate counts, recalculates HOT badges nightly, and the optional audit log records timestamps, IP addresses, and user agents with configurable retention.
* **Secure download handler.** Files live behind an `.htaccess`-protected directory; every download routes through PHP with nonce verification, role checks, rate limiting, and hotlink protection. X-Sendfile and X-Accel-Redirect supported for high-traffic hosts.
* **Cyrillic transliteration.** Uploaded filenames and category slugs are automatically transliterated from Serbian Cyrillic to Latin for safe disk storage; display titles keep their original characters.
* **Native builder support.** Gutenberg blocks ship out of the box. Eight shortcodes work in Elementor, WPBakery, Divi, Beaver Builder, and Bricks — see the [full shortcode reference](#shortcodes) below.
* **Themeable via CSS variables.** Recolor every card, badge, and icon from **Appearance → Customize → Additional CSS** without writing complex selectors — see [customizing appearance](#customizing-appearance) below.

= Architecture in one line =

The filesystem **is** the source of truth. Moving a download to a different category auto-moves its files on disk; deleting a category is blocked if any download still references it. This is what lets external automation tools sync files in and out without having to understand WordPress internals.

= Extensions (coming soon) =

* **I-Soft File Manager: Sentinel** — server-side automation. Monitors category folders for new files, creates draft download entries, and supports rclone mirroring, SFTP bulk upload, and scheduled folder scans.
* **I-Soft File Manager: Orbit** — Google Shared Drive sync. Departments drop files into shared folders; Orbit imports them as drafts for review.
* **I-Soft File Manager: Nomad** — one-shot importer from jDownloads. Rebuilds your categories, downloads, files, and counters in Foundation, preserving slug paths so existing URLs keep working.

== Installation ==

1. Upload the `isoft-fm-foundation` folder to `/wp-content/plugins/`, or install via **Plugins → Add New → Upload Plugin**.
2. Activate through the **Plugins** menu.
3. Visit **I-Soft File Manager: Foundation → Settings** to configure storage, access roles, PDF thumbnails, and log retention.
4. Create at least one **Download Category** — this is required before any file can be uploaded. Categories map directly to folder names under `wp-content/uploads/isoft-fmf-files/`.
5. To restrict an editor to specific folders, edit their user profile (**Users → Edit User**) and check the allowed categories under **I-Soft File Manager: Foundation — Allowed Categories**. Leave empty for no access. Administrators are always unrestricted.

= Server requirements =

* PHP 8.4 or higher
* WordPress 6.7 or higher
* MySQL 5.7+ or MariaDB 10.3+
* Apache with `mod_rewrite` + `mod_authz_core`, or Nginx
* Database user with `CREATE`, `ALTER`, `INDEX`, `REFERENCES`, and `DROP` on the WordPress database (needed once on activation to create the plugin's tables; common on locked-down shared hosts to be missing)
* Imagick PHP extension for PDF thumbnails

== Frequently Asked Questions ==

= Why not just use the default WordPress Media Library? =

The Media Library flattens everything into `wp-content/uploads/YYYY/MM/`, which breaks predictable automation and makes category-based permissions impossible. Foundation keeps files organized by category — which is what large organizations actually need when managing thousands of documents.

= How does the Category-as-Folder architecture work? =

Foundation stores files outside the Media Library in a predictable per-category folder tree (`wp-content/uploads/isoft-fmf-files/`). The filesystem is the source of truth. Moving a download to a different category auto-moves its files on disk. This lets external automation tools (Rclone, SFTP, scheduled scans) sync files in and out without having to understand WordPress internals.

= Can I migrate from jDownloads? =

Yes — but via a separate companion plugin, **I-Soft File Manager: Nomad** (coming soon). Foundation's data model is intentionally close to jDownloads to make a one-shot import practical; Nomad reads the legacy tables directly and rebuilds the category tree, downloads, files, and counters into Foundation, preserving slug paths so existing URLs keep working.

= How do I restrict a user to only some categories? =

Open their user profile (**Users → Edit User**), scroll to **I-Soft File Manager: Foundation — Allowed Categories**, and check the boxes. Users inherit access to every descendant of a checked category. Administrators bypass all access checks.

= What happens to downloads if I delete a category? =

Deletion is blocked if any download is still assigned to that category — the files would otherwise be orphaned. Reassign or delete the downloads first, then delete the category.

= What file types are allowed? =

Configured under **Settings → General → Allowed File Extensions**. The default list covers common document, archive, image, audio, and video formats. Executables (`.exe`, `.sh`, `.bat`) are not in the default list and should only be added if you know what you're doing.

= Does it support Cyrillic or other non-Latin filenames? =

Yes. Uploaded filenames and category slugs are automatically transliterated from Serbian Cyrillic to Latin for disk storage. Display titles keep the original characters. A setting under **Settings → General → Cyrillic Titles** can auto-fill download titles in Cyrillic from uploaded Latin filenames.

= Does it work with Full Site Editing (block) themes? =

Yes. The plugin detects FSE themes and injects the download card via the `the_content` filter. Classic theme templates under `templates/` are used as a fallback.

= Does it work with Elementor, WPBakery, Divi, Beaver Builder, or Bricks? =

Yes. Foundation ships eight shortcodes that drop into every major builder's HTML or Shortcode widget. See the [full shortcode reference](#shortcodes) below for attributes; the [builder widget table](#shortcodes) at the end of that section shows which widget to paste into in each builder. Native, point-and-click widgets for each builder are planned as separate companion plugins.

= The plugin installed but every upload fails with "Could not save file record." What's wrong? =

Your WordPress database user almost certainly lacks the `CREATE` privilege, so the plugin's tables never got created on activation. As of 0.10.19 every admin page shows the exact MySQL error plus a ready-to-paste `GRANT` statement to fix it; visit any plugin admin page to see the notice. On many cPanel hosts you'll need to also re-add the user to the database via cPanel after granting in SQL — the literal-vs-wildcard underscore escaping bites here.

== Shortcodes ==

Foundation ships eight shortcodes. Drop them in any classic-editor page, any Gutenberg "Shortcode" block, or any builder's HTML / Shortcode widget. Every `category` and `tag` attribute accepts either the term **slug** (preferred — stable across exports) or its numeric **term ID**. Slugs are visible on the Downloads → Categories screen and as the chip next to the category name on the download edit screen.

= [isoft_fmf_list] — filtered list of downloads =

Renders a grid, list, or table of downloads. Same renderer as the Download List block.

| Attribute | Default | What |
|---|---|---|
| `category` | empty | slug or term ID; empty = all categories |
| `include_subcategories` | `"1"` | also include downloads in descendant categories; `"0"` restricts to the exact category |
| `tag` | empty | slug or term ID |
| `limit` | per-page setting | how many to render |
| `orderby` | `"date"` | `date` / `title` / `download_count` / any WP_Query orderby |
| `order` | `"DESC"` | `ASC` / `DESC` |
| `layout` | display setting | `grid` / `list` / `table` |
| `show_search` | `"0"` | render a search box scoped to the same category |

Common recipes:

`[isoft_fmf_list category="resolutions"]`
`[isoft_fmf_list category="resolutions" layout="grid" limit="12"]`
`[isoft_fmf_list category="resolutions" orderby="download_count"]`
`[isoft_fmf_list category="resolutions" show_search="1"]`

= [isoft_fmf_download id="123"] — a single download card =

Renders one download's full card by post ID.

| Attribute | Default | What |
|---|---|---|
| `id` | required | the download post ID |
| `show_description` | `"1"` | include the description below the title |
| `show_files` | `"1"` | render the per-file list with download buttons |
| `style` | `"card"` | `card` / `compact` / `button-only` |

= [isoft_fmf_categories] — category grid =

Clickable cards leading to each category's archive page. Same renderer as the Category Grid block.

| Attribute | Default | What |
|---|---|---|
| `parent` | `0` | term ID of the parent category to show children of; `0` shows top-level |
| `columns` | `3` | grid columns |
| `show_count` | `"1"` | show the count of downloads per category |
| `show_description` | `"1"` | show the category description text |

= [isoft_fmf_search] — search box =

Renders a search form that filters Foundation downloads.

| Attribute | Default | What |
|---|---|---|
| `category` | empty | scope the search to a single category (slug or term ID) |
| `placeholder` | "Search downloads…" | the input placeholder text |

= [isoft_fmf_recent] — latest downloads =

Pre-set list, newest first.

| Attribute | Default | What |
|---|---|---|
| `limit` | `5` | how many to render |
| `days` | `0` | restrict to downloads posted in the last N days; `0` = no time limit |
| `category` | empty | scope to a single category |

= [isoft_fmf_popular] — most-downloaded =

Pre-set list, ordered by download count.

| Attribute | Default | What |
|---|---|---|
| `limit` | `5` | how many to render |
| `period` | `"all"` | `all` / `30d` / `7d` |
| `category` | empty | scope to a single category |

= [isoft_fmf_button file_id="42"] — a single download button =

Renders just a download button for one specific file (not the whole download post). Useful inline in body content.

| Attribute | Default | What |
|---|---|---|
| `file_id` | required | the file ID (visible in the per-file list on the download edit screen) |
| `text` | "Download" | button label |
| `class` | empty | extra CSS classes appended to the button |

= [isoft_fmf_count] — download counter =

Renders just a number — either a single file's count or a whole download post's total.

| Attribute | Default | What |
|---|---|---|
| `id` | `0` | download post ID (uses the post's aggregate counter) |
| `file_id` | `0` | specific file ID (uses that file's counter) |
| `format` | `"%s"` | sprintf format string for the number, e.g. `"%s downloads"` |

Exactly one of `id` or `file_id` should be set.

= Builder widget reference =

The shortcodes above drop into every major builder's HTML or Shortcode widget. Where to paste:

| Builder | Widget |
|---|---|
| Elementor | Widgets panel → **Shortcode** |
| WPBakery | Add element → **Text Block** (source view) or **Raw HTML** |
| Divi | Module → **Code** |
| Beaver Builder | Basic Modules → **HTML** |
| Bricks | Basic Elements → **Shortcode** |

Native, point-and-click widgets for each builder (with full attribute panels instead of writing shortcode strings) are planned as separate companion plugins.

== Customizing appearance ==

I-Soft File Manager: Foundation exposes its styling via CSS custom properties on `:root` so you can recolor cards from **Appearance → Customize → Additional CSS** without writing any selectors.

Example — recolor the PDF icon to match your theme blue and soften card borders:

`:root {
    --isoft-fmf-icon-pdf-bg: #1a73e8;
    --isoft-fmf-card-border: #ddd;
}`

= Available CSS variables =

* `--isoft-fmf-card-bg` — Card background
* `--isoft-fmf-card-border` — Card and grid borders
* `--isoft-fmf-row-border` — Per-file row separator
* `--isoft-fmf-title-band-bg` — Grid-mode title band background
* `--isoft-fmf-meta-color` — Date / size / count text
* `--isoft-fmf-empty-color` — "No files available" text
* `--isoft-fmf-badge-hot-bg` — HOT badge background
* `--isoft-fmf-badge-hot-color` — HOT badge text
* `--isoft-fmf-icon-color` — File-type icon/badge text
* `--isoft-fmf-icon-pdf-bg` — PDF file color
* `--isoft-fmf-icon-doc-bg` — DOC / DOCX color
* `--isoft-fmf-icon-xls-bg` — XLS / XLSX color
* `--isoft-fmf-icon-ppt-bg` — PPT / PPTX color
* `--isoft-fmf-icon-zip-bg` — Archive (ZIP / RAR / 7Z) color
* `--isoft-fmf-icon-img-bg` — Image color
* `--isoft-fmf-icon-vid-bg` — Video color
* `--isoft-fmf-icon-aud-bg` — Audio color
* `--isoft-fmf-icon-file-bg` — Generic / unknown file color

= Targeting individual classes =

For deeper changes (layout, spacing, typography), all public classes use the `.isoft-fmf-` prefix with BEM naming. Key entry points:

* `.isoft-fmf-download-card` — Outer wrapper around one download
* `.isoft-fmf-download-card__title` — Multi-file card heading
* `.isoft-fmf-file-item` — Per-file row
* `.isoft-fmf-file-item__icon` — Large file-type tile (list mode only)
* `.isoft-fmf-file-item__title` — File or download title link
* `.isoft-fmf-file-item__meta` — Date / size / count meta block
* `.isoft-fmf-file-item__action` — Action column (button or status label)
* `.isoft-fmf-download-btn` — The action button (intentionally not theme-locked via CSS variables; targets WP `wp-element-button` so theme styling stays in control)
* `.isoft-fmf-meta--type` — Inline file-type badge (grid mode only)
* `.isoft-fmf-badge--hot` — HOT marker
* `.isoft-fmf-grid` — Grid wrapper (use `.isoft-fmf-grid--cols-3` etc. for per-column-count overrides)
* `.isoft-fmf-list-wrap` — List wrapper
* `.isoft-fmf-category-grid` — Category grid wrapper

== Source code ==

Full source — including the un-minified React/JSX for the three Gutenberg blocks — is hosted publicly at:

https://github.com/I-SOFT-Mionica/isoft-fm-foundation

The compiled block bundles shipped under `blocks/build/` are produced from `blocks/<block-name>/{index,edit}.js` via `@wordpress/scripts` (webpack). To rebuild from a clean checkout:

`npm install
npm run build`

The build script reads `webpack.config.js`, compiles each block's `index.js` entry, and writes `blocks/build/<block-name>.js` plus an `<block-name>.asset.php` dependency manifest. Running `npm run start` instead watches the sources and rebuilds on save during development.

== Screenshots ==

1. Download edit screen with drag-and-drop upload, per-file progress, and the From Folder browser.
2. Download list block in grid mode — portrait tiles with file-type badges.
3. Per-user category ACL tree on the profile screen.
4. Statistics dashboard with HOT downloads and per-file counts.
5. Download handler settings — security, logging, and serve method.

== Changelog ==

= 0.10.19 =
* **Database setup failures are now visible.** When the plugin can't create its required tables (usually because the WordPress database user lacks `CREATE` privilege — common on locked-down shared hosts), every admin page now shows a clear warning with the exact MySQL error and a ready-to-paste `GRANT` statement to fix it. Previously the install appeared to succeed but every upload silently failed with "Could not save file record" and the admin had no clue why.
* **More helpful upload and save errors.** Failed file uploads now report the specific reason (file too large, server out of temp space, security plugin blocked it, etc.) instead of a generic "upload error." Failed database saves include the underlying MySQL error so you can see whether a column is missing, the disk is full, or something else.

= 0.10.18 =
* **New: External Link Target setting** under Settings → Display. Choose whether external-URL download buttons (Google Drive, Dropbox, etc.) open in a new tab or the same window. Defaults to new tab — the modern web convention and what visitors usually expect for off-site links. Local-file downloads are unaffected.
* **Fixed: downloads failed to start on Nginx and similar hosts** where auto-mode picked a serve method the server couldn't actually fulfill. Auto-mode now safely streams via PHP; admins who have X-Sendfile or X-Accel-Redirect properly configured can opt in via Settings → Security → File Serving Method.
* **Fixed: PHP-streamed downloads hung** on hosts with output gzip, nested output buffers, or aggressive FastCGI buffering. The stream now drains every level of output buffering, disables on-the-fly compression, and pushes data in 8 KB chunks so the file starts arriving immediately instead of waiting for the whole response to assemble.
* **Fixed: Cyrillic-titled downloads kept the locale's auto-draft slug** (for example `automatski-nacrt` under Serbian Latin, `auto-draft` under English) instead of transliterating the title. User-customized slugs are still preserved as before.
* **Fixed: download links silently failed on themes using AJAX page navigation** (djax, pjax, swup, hotwire-turbo, instantclick). Those libraries intercept every link click and try to swap the response into the page as HTML — which explodes when the response is a binary file. Download buttons now ship with the HTML5 `download` attribute and the plugin's own click handler runs first so the theme can't hijack the request.

= 0.10.17 =
* **Smart ZIP bundle cache expiry.** Bundle cache now expires on *idle time* (last access), not build age — so a bundle being downloaded daily no longer gets rebuilt on a schedule. A hard ceiling at 3× the configured duration guarantees an eventual refresh regardless.

= 0.10.13 — 0.10.16 =
* **Faster statistics dashboard.** Now reads from a daily aggregate table instead of scanning the full per-event log; refreshes immediately on every download instead of after a 5-minute cache.
* **ZIP bundle self-cleanup.** A daily sweep removes stale or orphaned bundles from disk; deleting a download immediately clears its cache. A "Clear bundle cache now" button covers manual cleanup.
* **Broken-link recovery on Windows hosts.** Renamed or moved files are now relinked via SHA-256 content matching, not just POSIX inode (Linux/POSIX still uses the faster inode path).
* **Manual "Run integrity check now" button** on the Broken Links screen, with overlap protection and auto-recovery if the scan crashes mid-flight.
* **Realistic demo content** that models a real document lifecycle (release-day spike, decay, weekend dampening) so first-impression screenshots look like a live site.

= 0.10.0 — 0.10.12 =
* **Category-level access roles.** Categories can declare their own minimum role (Public, Subscriber+, Editor+, etc.). Downloads can be set to inherit the strictest permission across their assigned categories.
* **One-click ZIP bundles** for multi-file downloads. Visitors can grab everything as a single archive; optional on-disk cache via Settings → Display.
* **Multi-file summary tiles.** In listings, multi-file downloads now render as a single summary card (file count, distinct types, total size, single "Download all" button) instead of one row per file.
* **User-agent blocklist** under Settings → Security for blocking specific scrapers or bots.
* **Featured-first listings.** Downloads marked Featured pin to the top of any list regardless of sort order.
* **External-only flag** for downloads where the external URL is canonical and the local file is just a backup.
* Plus a bundle of fixes for the Category Grid block and a settings-tab regression where saving one tab wiped checkboxes on the others.

= 0.8.0 — 0.9.1 =
* **WordPress.org review preparation.** Full Coding Standards compliance pass, escape and nonce audits, REST capability hardening, and the official rename to **I-Soft File Manager: Foundation** (slug `isoft-fm-foundation`).

= 0.7.0 — 0.7.2 =
* **Themeable via CSS custom properties.** Every color in the public stylesheet is exposed as a `--isoft-fmf-*` variable on `:root` — recolor anything by overriding a single variable in Customizer → Additional CSS.
* **Demo content creates a showcase page** automatically so new users can see all three embed patterns side-by-side.

= 0.5.0 — 0.6.1 =
* **Rate limiting and hotlink protection** fully enforced (configurable per IP/hour, configurable referer check).
* **Lighter PDF thumbnail backend** — Imagick-only; removed every `exec()` call from the codebase.

= 0.4.0 — 0.4.9 =
* **Broken-link recovery.** Daily scheduled scan detects missing files. Visitors hitting a missing file see a friendly "temporarily unavailable" page instead of an error.
* **Object cache layer** for hot-path reads, and the **Broken Links admin screen** with per-row recovery options (Move back, Reassign, Split, Reupload, Detach).
* **Container-query grid** that adapts to any sidebar or column width.

= 0.3.0 — 0.3.9 =
* **Per-user category access control** with subtree inheritance.
* **Grid view redesign** with colored file-type badges and clean layout bands.

= 0.2.1 — 0.2.9 =
* **Category-as-folder architecture introduced.** Creating a category creates a real physical folder on the server.
* **From Folder browser** for files dropped in via external tools.
* **Cyrillic-to-Latin transliteration** for safe filesystem storage.
* **Inline metadata editing** directly from the file list.

== Upgrade Notice ==

= 0.10.19 =
If the plugin appeared to install but your uploads kept failing with "Could not save file record," this release surfaces the underlying cause (usually a missing database privilege) and gives you the SQL to fix it. Otherwise routine.
