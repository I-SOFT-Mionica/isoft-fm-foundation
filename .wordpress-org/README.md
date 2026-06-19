# WordPress.org plugin assets

Files in this directory get synced to `/assets/` in the WordPress.org SVN
repo by `.github/workflows/deploy-to-wporg.yml`. They are **never bundled
into the distributed plugin zip** — different SVN namespace.

## Required files

| File | Dimensions | Where it shows |
|---|---|---|
| `banner-772x250.png` (or `.jpg`) | 772 × 250 | Top of the plugin's WP.org page |
| `banner-1544x500.png` (or `.jpg`) | 1544 × 500 | Same banner at 2× for retina/HiDPI |
| `icon-128x128.png` (or `.svg`) | 128 × 128 | Plugin card in WP admin → Add New search results |
| `icon-256x256.png` | 256 × 256 | Same icon at 2× for retina |

## Optional but recommended

| File | Notes |
|---|---|
| `screenshot-1.png` … `screenshot-N.png` | Order and count must match the `== Screenshots ==` section in `readme.txt`. Captions come from readme. |

## Rules

- **All files live flat in this directory.** No subfolders — the 10up deploy action syncs `.wordpress-org/*` straight into SVN `/assets/`, and WP.org only looks at the top level. Putting screenshots in `.wordpress-org/screenshots/` ships them to `/assets/screenshots/` where the renderer never finds them.
- **File names are case-sensitive** — WP.org's renderer expects exactly these names.
- **PNG, JPG, GIF** all work for banners and screenshots. **SVG** is allowed for icons only.
- Screenshots are typically rendered ~700px wide on the plugin page; design accordingly.
- WP.org caches assets aggressively. Allow ~15 minutes for changes to appear after a deploy.
- Asset-only updates (e.g. swapping a screenshot between releases) can ship without
  bumping the plugin version — but the simplest path is to just run the deploy workflow
  with the current version; the action is idempotent on trunk/tag content.
