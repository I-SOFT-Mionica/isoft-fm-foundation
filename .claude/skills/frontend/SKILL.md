---
name: frontend
description: CSS, Gutenberg blocks, shortcodes, public and admin templates, JS, asset enqueue rules, theming variables. Load when touching anything in public/, blocks/, admin/css/, admin/js/, templates/, or public/views/.
---

# Frontend playbook

## Naming — BEM with the plugin prefix

All public CSS classes follow BEM with the plugin prefix:

\```
.isoft-fmf-download-card               <- block
.isoft-fmf-download-card__title        <- element
.isoft-fmf-download-card--featured     <- modifier
\```

CSS custom properties on `:root` use the same prefix:

\```css
:root {
    --isoft-fmf-card-bg: #fff;
    --isoft-fmf-icon-pdf-bg: #c0392b;
}
\```

Eighteen custom properties are user-themable via Customizer → Additional CSS. The list is duplicated in two places that **must stay in sync** when you add or rename one:

- `readme.txt` — "Customizing appearance" section
- `admin/views/settings-page.php` — Display tab → Theming `<details>` table

If you forget to update one, the WP.org page and the admin reference will disagree, which has happened and is confusing.

## Asset enqueueing — lazy + deferred

Public CSS/JS only enqueue on pages that actually render plugin content. The gate is `ISOFT_FMF_Shortcodes::page_needs_assets()`, which returns true when:

- Single `isoft_fmf_file` post
- Download archive, or `isoft_fmf_category` / `isoft_fmf_tag` archive
- A single post contains one of our shortcodes (`has_shortcode`)
- A single post contains one of our blocks (`has_block`)

The script enqueue uses the array-args form for `defer`:

\```php
wp_enqueue_script(
    'isoft-fmf-public',
    ISOFT_FMF_PLUGIN_URL . 'public/js/public-script.js',
    array(),
    ISOFT_FMF_VERSION,
    array(
        'in_footer' => true,
        'strategy'  => 'defer',
    )
);
\```

Regress on either and Plugin Check flags `EnqueuedStylesScope` / `EnqueuedScriptsScope` / `NonBlockingScripts.NoStrategy`. Don't.

## Gutenberg blocks

Three blocks live under `blocks/`:

- `download-list`
- `download-button`
- `category-grid`

Each has:

- `block.json` — block metadata
- `index.js` — registration entry point
- `edit.js` — editor-side JSX
- `render.php` — server render callback (output escaped via `wp_kses` allowlist)

Block names use the **slug** form (`isoft-fm-foundation/download-list`), not the prefix form. The slug is immutable identity; the prefix is internal naming.

Build flow:

\```bash
npm install
npm run build   # webpack via @wordpress/scripts
\```

Outputs land in `blocks/build/<name>.js` plus `<name>.asset.php` (the dependency manifest). `.distignore` excludes `blocks/*/edit.js` and `blocks/*/index.js` from the zip — we ship only the compiled bundles. Source paths are declared in `readme.txt` `== Source code ==` (required by WP.org since we ship minified JS).

`npm run start` runs webpack in watch mode for development.

## Template hierarchy overrides

Theme overrides under `templates/`:

- `archive-isoft_fmf_file.php`
- `single-isoft_fmf_file.php`
- `taxonomy-isoft_fmf_category.php`
- `taxonomy-isoft_fmf_tag.php`

These are loaded by WP's standard template hierarchy — themes can override by copying into their own folder. No custom loader.

For block (FSE) themes, the single-download template is also registered via `register_block_template()` in `ISOFT_FMF_Blocks::register_block_template()`. Requires WP 6.7+ (hence `Requires at least: 6.7`).

## Container queries, not media queries

The download list adapts to its container (sidebar, full-width, two-column) rather than the viewport. Use `@container` queries throughout. Grid tracks use `auto-fill minmax(...)` with rem-based sizes.

Don't introduce media queries unless you genuinely need viewport-based behaviour. Cards dropped into a 300px sidebar should look right at that width without the viewport knowing.

## File-type icons + badges

Per-extension color tokens live in the CSS variable set (`--isoft-fmf-icon-pdf-bg`, `--isoft-fmf-icon-doc-bg`, etc.). The helper `isoft_fmf_mime_icon_class( $ext )` in `includes/functions.php` returns the class fragment (e.g. `pdf`, `doc`, `zip`). Use this in templates; don't hand-build the class name.

## Admin-side UI

Admin views live under `admin/views/`. Same `wp-element-button` / dashicons palette as the front end where possible. Don't reach for jQuery UI — vanilla JS or `@wordpress/components` (in block contexts).

For admin-side JS that calls our AJAX endpoints, use `wp_localize_script` to pass the nonce and URLs — never inline `<script>`.

## Things never to do on the frontend

- Inline `<style>` or `<script>` blocks — always enqueue
- Output unescaped variables (even ones you escaped internally — escape *late* at the echo site). Reach for `wp_kses` with the narrow allowlist when output is HTML fragments. See `security` skill.
- Use admin-only WP functions (`wp_handle_upload`, `wp_tempnam`, etc.) without `require_once`. See `wp-conventions` skill.

## When making visual changes

1. Edit the relevant CSS, template, or block source
2. If touching blocks, run `npm run build` so the compiled bundles match
3. If adding a CSS variable, update **both** the readme.txt list and the Display-tab table
4. Verify the change renders without enqueuing on pages that don't need the asset
5. For block changes, eyeball both the editor (visual) and the saved post-content markup (the `wp:isoft-fm-foundation/...` comment + attribute JSON)

## Cross-cutting

- PHP-side rendering rules (escaping, hooks) → `wp-conventions` skill
- Output escaping policy → `security` skill
- Testing render output → `qa` skill (mostly integration-only, see notes there)
