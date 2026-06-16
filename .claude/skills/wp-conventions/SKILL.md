---
name: wp-conventions
description: PHP class structure, hook registration, prefix rules, WPCS quirks, Plugin Check traps, custom-table patterns. Load when writing or refactoring PHP, debating naming, or before responding to a WPCS / Plugin Check failure.
---

# WordPress conventions playbook

## The prefix story

Two parallel naming families coexist. **Don't mix them.**

| What | Form | Example |
|---|---|---|
| PHP classes | `ISOFT_FMF_` snake-pascal | `ISOFT_FMF_File_Manager` |
| PHP constants | `ISOFT_FMF_` all-caps | `ISOFT_FMF_VERSION` |
| PHP functions | `isoft_fmf_` snake | `isoft_fmf_get_settings()` |
| Options, postmeta, usermeta, capabilities | `isoft_fmf_` / `_isoft_fmf_` | `_isoft_fmf_access_role` |
| Post type, taxonomies, hook names, AJAX actions | `isoft_fmf_` | `isoft_fmf_file`, `wp_ajax_isoft_fmf_upload_file` |
| DB tables | `wp_isoft_fmf_` | `wp_isoft_fmf_files` |
| CSS classes | `.isoft-fmf-` | `.isoft-fmf-card` |
| CSS custom properties | `--isoft-fmf-` | `--isoft-fmf-card-bg` |
| File storage dir | `isoft-fmf-files/` | under `wp-content/uploads/` |

The **slug** form (`isoft-fm-foundation`) is separate and **must never become the prefix**. Slug applies to:

- `Text Domain:` header
- REST namespace (`isoft-fm-foundation/v1`)
- Block names (`isoft-fm-foundation/download-list`)
- WP.org plugin slug

History: `idl_*` → `isfm_*` (0.8.0 rename) → `isoft_fmf_*` (0.9.1 prefix bump). The 8-char form exists because the WP.org reviewer flagged `isfm_` at 4 chars as collision-prone (round 3, 90k+ plugins in the directory). Don't bump again without a strong reason.

## Class autoloader

`isoft-fm-foundation.php` registers an SPL autoloader: `ISOFT_FMF_X_Y` → `includes/class-x-y.php` (lowercase, underscores become dashes).

Adding a new class:

1. Create `includes/class-<dash-separated>.php` defining `class ISOFT_FMF_<Pascal_Snake>`
2. Instantiate + register in the appropriate block of `isoft-fm-foundation.php`:

   \```php
   ( new ISOFT_FMF_Foo() )->register_hooks();
   \```

3. Put inside `if ( is_admin() )` only for admin-only classes (meta boxes, admin columns, settings). Frontend handlers (download, bundle, shortcodes, blocks, REST, access control) stay outside.

## Hook registration pattern

Every class with hooks has a `register_hooks(): void` method. Hooks are added there, **never in the constructor**. Keeps the factory pattern simple and lets tests instantiate without side effects.

\```php
class ISOFT_FMF_Foo {
    public function register_hooks(): void {
        add_action( 'init', array( $this, 'register' ) );
    }
}
\```

## Admin-only WP functions — frontend trap

These live in `wp-admin/includes/*.php` and are **not** available on `template_redirect`, REST routes, or any public-facing path:

| Function | File to require first |
|---|---|
| `wp_tempnam` | `wp-admin/includes/file.php` |
| `wp_handle_upload` | `wp-admin/includes/file.php` |
| `wp_upload_bits` | `wp-admin/includes/file.php` |
| `wp_generate_attachment_metadata` | `wp-admin/includes/image.php` |
| `media_handle_upload`, `media_sideload_image` | `wp-admin/includes/media.php` |

If your handler runs from a public hook, either:

\```php
require_once ABSPATH . 'wp-admin/includes/file.php';
wp_handle_upload( ... );  // immediately
\```

…with a comment naming the function being loaded, or use the PHP-native equivalent (`tempnam( sys_get_temp_dir(), ... )`). The WP.org reviewer accepts the `require_once` pattern when the function is called immediately after. We bit ourselves on this at 0.10.0 with the ZIP bundle handler — fixed in 0.10.1.

## WPCS quirks

- **Use `array()`, not `[]`.** WordPress core convention. PHP-8.4 short-array hints are ignored.
- **Keep parens on chained constructors.** `( new Foo() )->method()` — not `new Foo()->method()`. Older static analysers choke; we keep parens deliberately (changelog 0.6.1).
- **Array `=>` alignment within a contiguous block.** PHPCS wants all `=>` in adjacent array rows to line up. Break the block with a comment or blank line to start a new alignment group.
- **`LongIndexSpaceBeforeDoubleArrow`** — when the gap between shortest and longest key is large, PHPCS flips and demands **single space** instead of alignment. The exact threshold isn't documented; bias to single-space when keys vary by more than 4-5 chars.
- **Equals-sign alignment** between adjacent variable assignments works the same way: contiguous block, aligned to the longest LHS, broken by intervening code.

## Plugin Check traps that keep recurring

- **`Stable tag` mismatches the SVN tag** → users get the wrong release. Deploy workflow pre-flight catches this.
- **Hidden files in the zip.** `.gitkeep`, `.DS_Store`, `.env`, etc. → `hidden_files`. Use `index.php` with `// Silence is golden.` to keep empty dirs.
- **`Domain Path: /languages`** requires the directory to actually exist in the zip. Don't delete it during a cleanup pass.
- **`outdated_tested_upto_header`** — bump `Tested up to:` when a new WP major releases.
- **`wp_function_not_compatible_with_requires_wp`** — `Requires at least:` must cover every WP API we call. Currently 6.7 for `register_block_template()`.
- **`PrefixAllGlobals.NonPrefixedVariableFound`** — view files include local PHP variables passed in by the rendering class. WPCS thinks they're globals. Add `// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Locals passed in by including class.` at the top of view files (already done for the existing set; remember when adding new templates / views).
- **`NonceVerification.Missing` on hooks** — even when WP core verified the nonce upstream (e.g. `save_post`, `edited_<taxonomy>`), reviewer wants explicit `wp_verify_nonce()`. See `security` skill.

## Custom tables

Three: `wp_isoft_fmf_files`, `wp_isoft_fmf_download_log`, `wp_isoft_fmf_licenses`. All managed via the matching `ISOFT_FMF_*_Manager` class with `wp_cache_*` layers.

Schema goes through `dbDelta()` in `ISOFT_FMF_Activator::create_tables()`. Bump `ISOFT_FMF_VERSION` so the version-check on activation re-runs.

For data migrations on version transitions, use `ISOFT_FMF_Activator::run_migrations( $from_version )` — the 0.10.0 effective-role backfill is the canonical example.

## `phpcs.xml.dist`

When adding a new prefix family or a new directory to scan, update `phpcs.xml.dist`:

- Prefix list under `WordPress.NamingConventions.PrefixAllGlobals`
- Exclude patterns for view / template files where local vars look like globals

## Cross-cutting

- Sanitisation, escaping, nonces, capability checks → `security` skill
- Test patterns and the wp_update_post / edit_date trap → `qa` skill
- Frontend rendering → `frontend` skill
- CI / deploy mechanics → `cicd` skill
