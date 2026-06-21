# I-Soft File Manager: Foundation — project guide

A WordPress plugin (slug `isoft-fm-foundation`, internal prefix `isoft_fmf` / `ISOFT_FMF_`) for hierarchical document management. Distributed via WordPress.org SVN; developed on GitHub.

## Repo layout

| Path | What |
|---|---|
| `isoft-fm-foundation.php` | Plugin entry file (header, constants, autoloader, bootstrap) |
| `includes/` | PHP classes — autoloader maps `ISOFT_FMF_Foo_Bar` → `class-foo-bar.php` |
| `admin/` | Admin-only views, CSS, JS |
| `public/` | Frontend views, CSS, JS |
| `blocks/` | Gutenberg block sources (`<name>/index.js`, `edit.js`, `render.php`) — compiled output in `blocks/build/` |
| `templates/` | Theme template overrides for our post type / taxonomies |
| `tests/` | PHPUnit suite |
| `.github/workflows/` | CI (WPCS, PHPUnit, Plugin Check) + manual deploy |
| `.wordpress-org/` | Banner / icon / screenshots synced to SVN `/assets/` on deploy |
| `.claude/skills/` | Focused skill packs (this directory; excluded from plugin zip) |

## Common commands

| Task | Command |
|---|---|
| Build the distributable zip | `cd .. && python build.py` → writes `../isoft-fm-foundation-<ver>.zip` |
| Run PHPCS | `vendor/bin/phpcs --standard=phpcs.xml.dist` (needs PHP 8.4) |
| Run PHPUnit | `vendor/bin/phpunit` (needs PHP 8.4 + `tests/wp-tests-config.php`) |
| Rebuild block bundles | `npm install && npm run build` |
| Watch CI for current commit | `gh run list --commit $(git rev-parse HEAD)` then `gh run watch <id> --exit-status` |
| Deploy to WP.org | Actions → **Deploy to WordPress.org** → Run workflow → enter version |

## Skill packs (load on demand)

Each skill is a focused playbook ≤200 lines. Invoke when its trigger fires.

| Skill | When to load |
|---|---|
| **`cicd`** | Any CI work — workflow runs, deploys, version-bump procedure, SVN, `gh` CLI patterns, Plugin Check workflow specifics |
| **`frontend`** | CSS, blocks, templates, shortcodes, JS, asset enqueue rules, theming variables — anything that produces what a user sees |
| **`wp-conventions`** | Writing or refactoring PHP — class structure, hooks, prefix rules, WPCS quirks, Plugin Check traps |
| **`security`** | Touching `$_POST` / `$_GET` / `$_FILES` / `$_SERVER`, AJAX endpoints, save handlers, anything that takes user input or sends output to the browser |
| **`qa`** | Writing or fixing tests, debugging PHPUnit failures, deciding what's testable vs integration-only |

Skills are loaded explicitly (`/skill <name>`) or invoked when the agent recognises a relevant task. Don't paraphrase a skill — read it.

## Always-on rules

Three things break the plugin if violated. Verify before pushing.

### 1. The slug stays `isoft-fm-foundation`. Never rename:

- `Text Domain:` plugin header
- REST namespace (`isoft-fm-foundation/v1`)
- Block names (`isoft-fm-foundation/download-list`, etc.)
- WordPress.org plugin slug

The **internal prefix** (`isoft_fmf_` / `ISOFT_FMF_`) is a separate concern — see `wp-conventions` skill.

### 2. Version metadata must agree across three locations before deploy.

- `Version:` header in `isoft-fm-foundation.php`
- `ISOFT_FMF_VERSION` constant directly below
- `Stable tag:` line in `readme.txt`

The deploy workflow's pre-flight step fails loud if any disagree. WordPress.org serves the SVN `tags/X.Y.Z/` directory whose number matches `Stable tag:` — drift here means users get the wrong release.

### 3. Admin-only WP functions must `require_once` first.

`wp_tempnam`, `wp_handle_upload`, `wp_upload_bits`, `wp_generate_attachment_metadata`, `media_*` all live in `wp-admin/includes/*.php` and are **not** auto-loaded on the frontend, REST, or `template_redirect` paths. Either `require_once ABSPATH . 'wp-admin/includes/file.php';` immediately before the call, or use the PHP-native equivalent (`tempnam( sys_get_temp_dir(), ... )`, etc.).

This bit us at 0.10.0 with the ZIP bundle handler — see CHANGELOG.

## Keep skills in sync with reality

Skills are playbooks for the next session, not historical records. When a change has shipped and is **confirmed working in real use** — manual test on Local, successful deploy, user confirmation; not just CI green — update the relevant skill if it:

- Establishes a new pattern future work should follow
- Reveals a trap future work could fall into
- Contradicts something a skill currently says

Routine fixes don't need a skill update. The bar: would a future agent be better served by this lesson?

The update can ride along on the change's branch (when discovered during the work) or land as a follow-up commit / PR (when the lesson surfaces only after merge). The 0.10.1 bundle-handler fatal is the canonical example — wrote the fix, *then* updated `wp-conventions` so the `wp_tempnam`-is-admin-only trap doesn't bite the next agent.

Same principle applies to the auto-memory files below for state changes (a release ships, a decision shifts, a deploy pattern changes). Skills hold procedure; memory holds state.

## Persistent context (auto-memory)

Project-specific facts — history, architecture decisions, submission status, dev tools — live in the auto-memory at `~/.claude/projects/d--i-Downloads-wordpress/memory/`. The index file `MEMORY.md` is loaded into every session; individual memory files are loaded on relevance.

When state changes (a release lands, a major decision is made, a deploy pattern shifts), update the relevant memory file rather than this `CLAUDE.md`. `CLAUDE.md` is for stable rules; memory is for evolving state.

## Future addons (separate plugins)

- **Sentinel** — server-side automation for category-folder syncing (rclone, SFTP, cron scans)
- **Orbit** — Google Shared Drive sync for editorial workflows
- **Arbiter** — one-shot jDownloads importer

None of these live in this repo. Each becomes its own WP.org submission when ready. Foundation declares them on the Extensions tab as "Coming soon."
