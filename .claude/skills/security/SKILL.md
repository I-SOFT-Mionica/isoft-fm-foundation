---
name: security
description: Sanitisation, escaping, nonces, capability checks, $_POST/$_GET/$_FILES/$_SERVER handling, path traversal, what WP.org reviewers flag. Load when touching user input, AJAX endpoints, save handlers, or output to the browser.
---

# Security playbook

## Four obligations on every input + output

1. **Sanitise early** — as soon as user input enters PHP. Never store raw `$_POST`.
2. **Validate always** — even after sanitising, check business rules (allowed values, ranges, ownership).
3. **Escape late** — at the point of output, with the function appropriate for the context.
4. **Verify nonce + capability separately** — a nonce proves the request came from a form on our site; a capability proves the user is allowed to do this. Both are required for state changes.

## Sanitisation map

| Input type | Function |
|---|---|
| Plain text | `sanitize_text_field( wp_unslash( $_POST['x'] ?? '' ) )` |
| Email | `sanitize_email( ... )` |
| URL (for storage) | `esc_url_raw( wp_unslash( ... ) )` |
| Key / slug | `sanitize_key( wp_unslash( ... ) )` |
| Integer / ID | `absint( $_POST['x'] ?? 0 )` — `absint` both unslashes and sanitises for numeric input |
| Textarea | `sanitize_textarea_field( wp_unslash( ... ) )` |
| Array of int IDs | `array_map( 'absint', (array) ( $_POST['ids'] ?? array() ) )` |
| HTML (rich text) | `wp_kses_post( wp_unslash( ... ) )` (post-content allowlist) or `wp_kses( ..., $custom )` (narrower) |
| File upload | Never trust `$_FILES['x']['type']`. Use `mime_content_type( $abs_path )` with `wp_check_filetype( $name )` as fallback |

WPCS sometimes can't trace sanitisation through `array_map` or custom helpers — use a `phpcs:ignore` comment with a `--` rationale when needed. See "phpcs:ignore patterns" below.

## Escaping map

| Output context | Function |
|---|---|
| HTML text content | `esc_html()` |
| HTML attribute value | `esc_attr()` |
| URL in `href` / `src` | `esc_url()` |
| Textarea value | `esc_textarea()` |
| Inline JS data | `wp_json_encode()` plus `esc_js()`, but prefer `wp_localize_script()` |
| HTML fragment we built ourselves | `wp_kses( $html, self::allowed_html() )` with a narrow allowlist |
| Translator-tagged i18n | `esc_html_e( 'string', 'isoft-fm-foundation' )` / `esc_attr_e( ... )` |

Plugin Check flags **every** `echo $variable;` of a variable, even if internally escaped — escape *late* at the echo site. For HTML fragments built from escaped pieces (like `ISOFT_FMF_Shortcodes::search_shortcode()` returning a form), wrap with `wp_kses` to satisfy the sniff. See `ISOFT_FMF_Shortcodes::allowed_html()` for the existing narrow-allowlist helper.

## Nonce + capability — three patterns

For classic form submits and admin-post handlers:

\```php
if ( ! isset( $_POST['_wpnonce'] )
    || ! wp_verify_nonce(
        sanitize_key( wp_unslash( $_POST['_wpnonce'] ) ),
        "isoft_fmf_<action>_{$context_id}"
    )
) {
    return;
}
if ( ! current_user_can( 'isoft_fmf_<cap>' ) ) {
    return;
}
\```

For AJAX (`wp_ajax_*` handlers):

\```php
check_ajax_referer( 'isoft_fmf_<action>', 'nonce' );
if ( ! current_user_can( 'isoft_fmf_<cap>' ) ) {
    wp_send_json_error( array( 'message' => __( '...', 'isoft-fm-foundation' ) ), 403 );
}
\```

For frontend handlers on `template_redirect`:

\```php
$nonce = isset( $_GET['nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['nonce'] ) ) : '';
if ( ! wp_verify_nonce( $nonce, "isoft_fmf_<action>_{$id}" ) ) {
    wp_die( esc_html__( 'Security check failed.', 'isoft-fm-foundation' ), 403 );
}
\```

## Reviewer trap — explicit nonces on every save hook

"Nonce verified by WP core" comments **are not accepted** even when WP did verify upstream (`save_post`, `edited_<taxonomy>`, `personal_options_update`, etc.). The reviewer wants an explicit `wp_verify_nonce()` against the matching WP nonce action:

| WP hook | Nonce action to verify |
|---|---|
| `save_post_<type>` | `update-post_<id>` |
| `created_<taxonomy>` | `add-tag` |
| `edited_<taxonomy>` | `update-tag_<id>` |
| `personal_options_update` | `update-user_<id>` |

Cheap, satisfies the reviewer, satisfies static analysers. Examples in `ISOFT_FMF_Category_ACL::enforce_category_on_save` and `ISOFT_FMF_Taxonomy::save_term_fields`.

## Path traversal guard

Whenever resolving user-supplied paths (file IDs, relative paths, slug fragments) against the storage directory:

\```php
$base = realpath( isoft_fmf_files_dir() );
$path = realpath( "{$base}/{$user_supplied_relative_path}" );
if ( ! $path || ! $base || ! str_starts_with( $path, $base ) ) {
    return null;  // or 404
}
\```

Existing reference: `ISOFT_FMF_Download_Handler::resolve_path()` and `ISOFT_FMF_Bundle_Handler::stream_bundle()`. Copy the pattern; don't roll your own.

## `phpcs:ignore` patterns

When WPCS can't trace sanitisation (custom static helpers, `array_map`, etc.):

\```php
// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- absint() on each element below covers both unslashing and sanitisation for numeric IDs.
$raw    = $_POST['tax_input']['isoft_fmf_category'];
$posted = is_array( $raw ) ? array_map( 'absint', $raw ) : array( absint( $raw ) );
\```

Rules:

- Always include the `--` reason
- Be specific about WHICH sniff (don't suppress the whole class)
- Reviewers grep for these — vague suppressions read as red flags

## Existing frontend gates (already wired; follow the pattern)

In `ISOFT_FMF_Download_Handler` and `ISOFT_FMF_Bundle_Handler`, the order of gates is:

1. Query var present → handle, else return
2. Feature toggle / extension availability (`ZipArchive` for bundles)
3. Nonce
4. Post status (`publish` only) + `post_password_required` block
5. `can_access_download()` via `ISOFT_FMF_Access_Control`
6. Hotlink protection
7. User-agent blocklist
8. Rate limit
9. Do the work

Follow the same order for any new public file-serving endpoint.

## Recurring reviewer punch-list (rounds 1-3)

These have all been flagged on this plugin at least once:

- Calling core loading files (`wp-blog-header.php`, `wp-load.php`) — never. Use hooks.
- Generic / too-short prefix — currently `isoft_fmf` (8 chars), don't shrink
- Compressed JS without source — `readme.txt` `== Source code ==` explains the npm build
- Direct DB queries in `uninstall.php` — use enumerated `delete_option()` calls
- Hidden files in the zip — use `index.php` not `.gitkeep`
- SSL-broken URLs in `Plugin URI` / `Author URI` headers — currently point at GitHub for that reason

## Cross-cutting

- WPCS suppression vocabulary → `wp-conventions` skill
- Writing security regression tests → `qa` skill
- Frontend-specific escaping (template files) → `frontend` skill
