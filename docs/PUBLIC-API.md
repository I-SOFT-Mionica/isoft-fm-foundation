# Public API for extensions

What other plugins (including the paid I-Soft File Manager extensions) may rely on. Everything listed here keeps its name, arguments and meaning across releases; a change goes through a deprecation period first. Anything not listed is internal and may change in any release.

A test (`PublicApiContractTest`) fails if a function or hook below disappears, so this list and the code cannot drift apart silently.

Conventions: functions are plain functions with the `isoft_fmf_` prefix. Nothing here makes a network request. Everything that changes what existing sites do is opt-in and inert until called.

## Registering an extension

- `ISOFT_FMF_Extension_Api::register( array $args )` / `isoft_fmf_register_extension()` with `slug`, `name`, `version`, inside the `isoft_fmf_extensions_init` action (fired on `init`, priority 20).
- `isoft_fmf_notify_admin( $message, $type, $key, $data )` queues an admin notice. With a `$key`, it replaces an unseen notice with the same key; `$data` is kept with it. `isoft_fmf_queued_notice( $key )` returns that unseen notice (or null), so a caller can fold repeats into one notice with running totals instead of queuing one per run.

## Admin menu

- `isoft_fmf_admin_parent_slug()` returns the slug of the Downloads menu (`edit.php?post_type=isoft_fmf_file`). Add extension screens with `add_submenu_page( isoft_fmf_admin_parent_slug(), ... )`.
- Capabilities: the `isoft_fmf_*` family (`isoft_fmf_manage_settings`, `isoft_fmf_manage_categories`, `isoft_fmf_view_logs`, `isoft_fmf_record_downloads`, ...). Administrators have all of them.

## Creating and reading content

| Function | Purpose |
|---|---|
| `isoft_fmf_create_draft_download( array $args ): int\|false` | `title`, `description`, `category_id`, `access_role`, `license_id`, optional `post_date` (`Y-m-d H:i:s`, site time; ignored when not a real date), optional `slug` (feature `draft_slug`: Latin rule applied and made unique at once, so `get_post_field( 'post_name', $id )` right after the call is the final slug) |
| `isoft_fmf_create_category( array $args ): array\|WP_Error` | Feature `create_category`. `name`, `parent` (term ID, 0 for top), optional `slug` (Latin rule applied), `description`, `access_role` (`public`, `subscriber`, `contributor`, `author`, `editor`, `administrator`), `license_id`, `pending` (bool). Returns `term_id`, the actual `slug` and the storage-relative `path`; the folder exists on return. A taken slug gets a suffix, never an error. An unknown parent, role or license is refused (`isoft_fmf_invalid_category`) and nothing is created. The same name in the same place returns `term_exists` with the existing term ID as the error data. PHP only, not reachable over REST |
| `isoft_fmf_set_download_count( $file_id, $count ): bool` | Feature `set_download_count`. Set a file's counter to an absolute value and refresh the download's total. Writes no log rows, so carried-over counts stay out of the statistics; a re-run changes nothing. False for a negative count or an unknown file. PHP only |
| `isoft_fmf_attach_file( $download_id, $source_abs, $args ): int\|WP_Error` | Put a server file into the download's category folder and register it. `mode`: `copy` (default), `move` (source removed only after the row is saved), `in_place` (file already sits directly in the category folder; renamed to its sanitized name if needed). A name clash returns `isoft_fmf_filename_collision`; nothing is renamed silently. Symlinks and non-regular files are refused. PHP only, not reachable over REST |
| `isoft_fmf_file_exists_by_hash( $sha256 ): int` | ID of a stored file with this SHA-256, or 0 (also for malformed input) |
| `isoft_fmf_get_local_files( $after_id = 0, $limit = 200 ): array` | Local file rows in ID order for batch sweeps: `id`, `download_id`, `abs_path`, `file_hash`, `file_size`, `is_missing`. Limit 1 to 1000 |
| `isoft_fmf_accept_file_content( $file_id ): array\|WP_Error` | Accept a stored file's current content as its new baseline (re-hash, save hash and size). Runs the veto first (`origin` `accept`). Returns `changed`, `file_hash`, `file_size`; fires `isoft_fmf_file_replaced` only when the content really changed |
| `isoft_fmf_relink_file( $file_id, $new_rel_path ): true\|WP_Error` | Point a file row at where its file now lives inside storage. Same category folder: the row is updated. Another category's folder: the download and its other files move to that category, as the Broken Links reassign recovery does. Refuses paths outside storage, symlinks, folders, and paths another row owns |
| `isoft_fmf_set_file_unavailable( $file_id, $unavailable ): bool`, `isoft_fmf_file_is_unavailable( $file_id ): bool` | Hold a file back without deleting it: it stays listed, its button becomes a passive label, the download handler refuses it, ZIP bundles skip it, the public API reports `unavailable: true` with no `download_url` |
| `isoft_fmf_unavailable_label(): string` | The text shown instead of the button for missing or unavailable files (Settings > Display, default "Temporarily unavailable") |
| `isoft_fmf_category_for_folder( $rel_folder ): int` | Category that owns a storage folder, or 0. Pending categories count |
| `isoft_fmf_file_for_path( $rel_path ): int` | File row registered at a storage-relative path, or 0 |
| `isoft_fmf_category_folder_path( $term_id ): string` | The folder of a category, relative to storage |
| `isoft_fmf_files_dir(): string` | Absolute storage root |
| `isoft_fmf_sanitize_filename()`, `isoft_fmf_autofill_title()`, `isoft_fmf_cyrillic_to_latin()` | Filename and title helpers |
| `isoft_fmf_record_download( $file_id, $context )` | Record a download served elsewhere (see `idempotency_key`) |
| `isoft_fmf_client_ip()` | The visitor's address, honouring the Client IP source setting |

## What counts as public

One rule, so front ends, gateways and extensions never re-implement it:

- `isoft_fmf_download_is_public( $download_id ): bool` - published, no password, effective access role public, not under a pending category. Filter `isoft_fmf_download_is_public`.
- `isoft_fmf_category_is_public( $term_id ): bool` - neither it nor any ancestor requires a role, and it is not pending.
- `isoft_fmf_public_file( $file_id ): ?array` - a local file an anonymous visitor may download, with `id`, `download_id`, `abs_path`, `rel_path`, `name`, `size`, `mime`, `sha256`; null when the download is not public, the file is external, missing or unavailable, the download is "external links only", or the path does not resolve inside storage.

The public REST API applies the same rule: downloads under a pending category are left out of lists (totals stay right) and answer 404 singly, and unavailable files carry no `download_url`.

## Feature detection

In PHP: `isoft_fmf_features(): string[]` and `isoft_fmf_has_feature( $feature ): bool`. Over HTTP: `GET /isoft-fm-foundation/v1/public/info` (only while the public API is on) returns `name`, `version`, `api` (1), the same `features` and a few public `settings`. Identifiers: `public_api`, `gates`, `record_downloads`, `record_idempotency`, `content_changed`, `pending_categories`, `unavailable_files`, `category_path`, `public_rule`, `attach_file`, `pre_attach_veto`, `file_state`, `thumbnail_sizes`, `task_budget`, `create_category`, `draft_slug`, `set_download_count`, `attention_screen`. They are only ever added. Filter `isoft_fmf_features` (extensions add identifiers prefixed with their slug). Test features, not version numbers.

A download's `thumbnail` carries `sizes`: every generated size plus `full`, each with `width`, `height`, `mime_type`, `source_url`, in the shape of WordPress' REST `media_details.sizes`.

Categories in the public API carry `path`: the slug chain from the top (`reports/2026`), the same as the storage folder and the archive URL.

## Pending categories

A category can be held back from the public until approved: it exists and files can be filed in it, but it (and everything beneath it) is hidden from visitors in category lists, shortcodes, blocks, the public REST API, download category lists, the category archive (404) and sitemaps.

- `isoft_fmf_set_category_pending( $term_id, $pending ): bool`
- `isoft_fmf_category_is_pending( $term_id ): bool`
- Action `isoft_fmf_category_pending_changed( $term_id, $pending )`.
- Users who can manage categories, cron and WP-CLI see pending categories. Pass `'isoft_fmf_include_pending' => true` to a `get_terms()` call to include them explicitly.
- Approving a category does not publish its downloads.
- Admin category lists show a Status column and an Approve action.

## Needs attention

One screen (Downloads > Needs attention, capability `isoft_fmf_manage_settings`) lists everything waiting on a person. Foundation fills it with missing files, categories waiting for approval, tasks that stopped early and files marked unavailable. Feature `attention_screen`.

Add a list inside `isoft_fmf_extensions_init`:

`isoft_fmf_register_attention_source( string $id, array $args ): bool`

| Key | Meaning |
|---|---|
| `label` | Heading of the list |
| `count` | `callable(): int`. Runs on admin page loads for the menu number, so it must be cheap |
| `items` | `callable( int $page, int $per_page ): array` of items, for that page only |
| `handle_action` | Optional. `callable( string $item_id, string $action ): true\|WP_Error`. Check the item's current state and any capability of your own here. Foundation has already checked the screen capability and the nonce |
| `capability` | Optional. The list is shown only to users with it |
| `in_menu_count` | Optional, default true. False lists the items without adding to the menu number. Use it for a state someone chose, which would otherwise keep the number from reaching zero |

An item is an array with `id` and `name`, and optionally `reason`, `date` (Unix timestamp), `download_id`, and `actions`. Each action has a `label` and either a `url` (a plain link) or an `action` (a key handed back to `handle_action` by a POST). An item without an `id` or `name` is dropped.

Each source keeps its own storage, so a deactivated plugin's items go with it. All text is treated as plain text and escaped by Foundation. A callback that throws shows "This list could not be read" and does not affect other lists. A list with nothing in it is not shown. `isoft_fmf_attention_url( $source )` links to the screen or to one list.

## Hooks

Actions: `isoft_fmf_file_uploaded( $file_id, $download_id )`, `isoft_fmf_file_deleted` (before the row goes), `isoft_fmf_file_removed_from_disk( $rel_path, $file_id, $download_id )` (an editor's delete also removed the stored file; skipped while another row uses the same path, and switched off by returning false from the filter `isoft_fmf_delete_file_from_disk( $remove, $file )`), `isoft_fmf_file_replaced( $file_id, $download_id )` (content replaced on purpose: a Broken Links reupload or `isoft_fmf_accept_file_content()`), `isoft_fmf_file_availability_changed( $file_id, $download_id, $unavailable )`, `isoft_fmf_integrity_check_complete( $summary )`, `isoft_fmf_download_logged`, `isoft_fmf_content_changed( $type, $id )` with `isoft_fmf_suspend_content_changed()` / `isoft_fmf_resume_content_changed()`. This is the one hook a static build or cache needs: it fires for downloads saved, trashed or deleted, file changes, licenses, category and tag edits (including Foundation term meta), term assignments and pending state. `$type` is `download`, `license`, `category`, `tag`, or `bulk` (id 0) after a suspended batch. It can fire more than once per request; listeners should be idempotent.

Filter `isoft_fmf_pre_attach_file( true|WP_Error $allowed, string $abs_path, array $context )`: return a `WP_Error` to refuse a file before it is stored. It runs on every way of adding or replacing file content: the upload form (on the PHP temp file, before it is copied into the public uploads directory), `isoft_fmf_attach_file()`, import from disk, the Broken Links reupload, and `isoft_fmf_accept_file_content()`. `$context` has `download_id`, `origin` (`upload`, `attach`, `import`, `replace`, `accept`), `original_name` and whatever the caller passed. A refusal without a status becomes HTTP 422. With no callback attached nothing changes.

## Heavy work: budgets and stops

Foundation's heavy tasks run within a time and memory budget and stop cleanly instead of letting PHP kill the request: the integrity check works in slices that continue in a cron run (`isoft_fmf_integrity_continue`), PDF thumbnails render in a one-off cron job (`isoft_fmf_pdf_thumbnail`) under Imagick resource limits, ZIP bundles have size caps, and the Broken Links search for a missing file gives up rather than hang the dialog.

Extensions can use the same tool for their own heavy work (feature `task_budget`):

```php
$budget = new ISOFT_FMF_Task_Budget( 'myext_import' ); // optional second argument: seconds
foreach ( $items as $item ) {
	process( $item );
	if ( $budget->exhausted() ) { // 'time' or 'memory' in $budget->reason()
		save_cursor( $item ); schedule_next_slice(); break;
	}
}
ISOFT_FMF_Task_Budget::guard( 'myext_render', fn() => render(), null ); // contains any Throwable and reports it
ISOFT_FMF_Task_Budget::report( 'myext_import', 'too_large', 'plain-text detail' );
```

The default budget is 20 seconds, never more than 60% of `max_execution_time` counted from the request start, and 80% of `memory_limit`. Filter `isoft_fmf_task_budget( $limits, $task )` (`seconds`, `memory_share`). Every stop fires `isoft_fmf_task_stopped( $task, $reason, $detail )`, is listed under Tools > Site Health > Info, and queues one admin notice per task and reason.

Limits for Foundation's own tasks: `isoft_fmf_pdf_thumbnail_limits` (`memory`, `map`, `disk`, `time`, `width`, `height`, `max_file_bytes`), `isoft_fmf_pdf_thumbnail_async` (false renders inside the upload request, for sites without WP-Cron), `isoft_fmf_bundle_limits` (`max_files`, default 100; `max_bytes`, default 512 MB; 0 means no limit) with `isoft_fmf_bundle_fits( $count, $bytes )`.

## Public styles and scripts

The public CSS and JS load in the page head when the page itself holds Foundation content: a shortcode or block in the post, a single download, or a download archive or category page. Content rendered from anywhere else (page builder templates, theme parts, popups, loop items) loads them when it renders, and WordPress prints them in the footer. To load them in the head on pages you know need them, return true from `isoft_fmf_needs_assets( $needs )`. To load them from your own code, call `ISOFT_FMF_Shortcodes::enqueue_public_assets()`; repeated calls are harmless.

## Storage that is a mount or a symlink

Foundation resolves every stored path with `realpath()` and refuses anything outside the resolved storage root, on a directory boundary (a sibling such as `isoft-fmf-files-backup` is outside).

- A drive **mounted at** the storage root, or at a category folder's path, is supported: the resolved path stays inside storage.
- A **symlink** that leads out of storage is not supported and will not serve. This is a deliberate security boundary; there is no setting to relax it.

## Storage guards

Foundation writes `.htaccess`, `web.config` and `index.php` into the storage root and keeps a small probe file there (`isoft-fmf-probe.txt`) for the Site Health reachability test. Extensions that walk storage (Sentinel's mirror watches) skip these, along with dot-files and `.bundle-cache/`. `isoft_fmf_blocked_extensions()` lists the file types that are refused everywhere.

## Compatibility promise

The functions, hooks and signatures on this page are public API. Additions are backwards compatible (new optional parameters, new keys in arrays). Removals and meaning changes are announced in the changelog one release ahead.
