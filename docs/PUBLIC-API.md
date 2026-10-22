# Public API for extensions

What other plugins (including the paid I-Soft File Manager extensions) may rely on. Everything listed here keeps its name, arguments and meaning across releases; a change goes through a deprecation period first. Anything not listed is internal and may change in any release.

A test (`PublicApiContractTest`) fails if a function or hook below disappears, so this list and the code cannot drift apart silently.

Conventions: functions are plain functions with the `isoft_fmf_` prefix. Nothing here makes a network request. Everything that changes what existing sites do is opt-in and inert until called.

## Registering an extension

- `ISOFT_FMF_Extension_Api::register( array $args )` / `isoft_fmf_register_extension()` with `slug`, `name`, `version`, inside the `isoft_fmf_extensions_init` action (fired on `init`, priority 20).
- `isoft_fmf_notify_admin()` queues an admin notice.

## Admin menu

- `isoft_fmf_admin_parent_slug()` returns the slug of the Downloads menu (`edit.php?post_type=isoft_fmf_file`). Add extension screens with `add_submenu_page( isoft_fmf_admin_parent_slug(), ... )`.
- Capabilities: the `isoft_fmf_*` family (`isoft_fmf_manage_settings`, `isoft_fmf_manage_categories`, `isoft_fmf_view_logs`, `isoft_fmf_record_downloads`, ...). Administrators have all of them.

## Creating and reading content

| Function | Purpose |
|---|---|
| `isoft_fmf_create_draft_download( array $args ): int\|false` | `title`, `description`, `category_id`, `access_role`, `license_id`, optional `post_date` (`Y-m-d H:i:s`, site time; ignored when not a real date) |
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

In PHP: `isoft_fmf_features(): string[]` and `isoft_fmf_has_feature( $feature ): bool`. Over HTTP: `GET /isoft-fm-foundation/v1/public/info` (only while the public API is on) returns `name`, `version`, `api` (1), the same `features` and a few public `settings`. Identifiers: `public_api`, `gates`, `record_downloads`, `record_idempotency`, `content_changed`, `pending_categories`, `unavailable_files`, `category_path`, `public_rule`, `attach_file`, `pre_attach_veto`, `file_state`, `thumbnail_sizes`. They are only ever added. Filter `isoft_fmf_features` (extensions add identifiers prefixed with their slug). Test features, not version numbers.

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

## Hooks

Actions: `isoft_fmf_file_uploaded( $file_id, $download_id )`, `isoft_fmf_file_deleted`, `isoft_fmf_file_replaced( $file_id, $download_id )` (content replaced on purpose: a Broken Links reupload or `isoft_fmf_accept_file_content()`), `isoft_fmf_file_availability_changed( $file_id, $download_id, $unavailable )`, `isoft_fmf_integrity_check_complete( $summary )`, `isoft_fmf_download_logged`, `isoft_fmf_content_changed( $type, $id )` with `isoft_fmf_suspend_content_changed()` / `isoft_fmf_resume_content_changed()`. This is the one hook a static build or cache needs: it fires for downloads saved, trashed or deleted, file changes, licenses, category and tag edits (including Foundation term meta), term assignments and pending state. `$type` is `download`, `license`, `category`, `tag`, or `bulk` (id 0) after a suspended batch. It can fire more than once per request; listeners should be idempotent.

Filter `isoft_fmf_pre_attach_file( true|WP_Error $allowed, string $abs_path, array $context )`: return a `WP_Error` to refuse a file before it is stored. It runs on every way of adding or replacing file content: the upload form (on the PHP temp file, before it is copied into the public uploads directory), `isoft_fmf_attach_file()`, import from disk, the Broken Links reupload, and `isoft_fmf_accept_file_content()`. `$context` has `download_id`, `origin` (`upload`, `attach`, `import`, `replace`, `accept`), `original_name` and whatever the caller passed. A refusal without a status becomes HTTP 422. With no callback attached nothing changes.

## Storage that is a mount or a symlink

Foundation resolves every stored path with `realpath()` and refuses anything outside the resolved storage root, on a directory boundary (a sibling such as `isoft-fmf-files-backup` is outside).

- A drive **mounted at** the storage root, or at a category folder's path, is supported: the resolved path stays inside storage.
- A **symlink** that leads out of storage is not supported and will not serve. This is a deliberate security boundary; there is no setting to relax it.

## Compatibility promise

The functions, hooks and signatures on this page are public API. Additions are backwards compatible (new optional parameters, new keys in arrays). Removals and meaning changes are announced in the changelog one release ahead.
