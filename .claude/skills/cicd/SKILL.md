---
name: cicd
description: GitHub Actions workflow operations, the version-bump procedure, the WordPress.org SVN deploy, and gh CLI patterns for this repo. Load when running CI, troubleshooting a workflow failure, cutting a release, or working with the deploy pipeline.
---

# CI/CD playbook

## The three required workflows

Every PR must pass all three before merge. They run on `push` and `pull_request` to `main`.

| Workflow | Tool | Gates |
|---|---|---|
| `WPCS` | PHPCS + WordPress Coding Standards | Style + many security sniffs |
| `PHPUnit` | wp-phpunit | Behavioural tests under `tests/` |
| `Plugin Check` | Official WP plugin-check, run via wp-env | The actual WordPress.org review checks |

`Plugin Check` uses our **custom workflow** (not `wordpress/plugin-check-action@stable`) because the upstream action triggers a wp-env URL-plugin download bug on Node 24 / libuv 1.52.1. The header comment in `.github/workflows/plugin-check.yml` has the full bisection story (upstream issue #579). Don't switch back without re-checking that bug.

## Block bundles must be built in CI

`blocks/build/` is gitignored. Two workflows need to run `npm ci && npm run build` between checkout and their respective scan/stage step, or the plugin ends up with empty `blocks/<name>/` folders (source is excluded by `.distignore` too):

- **`Plugin Check`** — Plugin Check's runtime checks (enqueued asset size, loading strategy) need the compiled JS to validate. Build step lives between `setup-node` and `Install wp-env`.
- **`Deploy to WordPress.org`** — the 10up action stages the checkout via `.distignore`. Without a build step before it, the SVN tag ships with no Gutenberg block bundles and every block renders blank on user sites. Build step lives between `actions/checkout` and `Verify version consistency`.

PHPCS and PHPUnit don't need block JS — leave them as is. The local zip build (`python build.py`) builds blocks itself, so it's safe regardless. The risk is exclusively the CI path.

## Polling CI after a push

\```bash
sleep 12  # let GitHub register the runs
HEAD_SHA=$(git rev-parse HEAD)
for RUN_ID in $(gh run list --commit "$HEAD_SHA" --json databaseId --jq '.[].databaseId'); do
  NAME=$(gh run view "$RUN_ID" --json name --jq '.name')
  timeout 900 gh run watch "$RUN_ID" --exit-status >/dev/null 2>&1
  STATUS=$(gh run view "$RUN_ID" --json conclusion --jq '.conclusion')
  echo "→ $NAME: $STATUS"
done
\```

On WPCS failure, the **check-runs annotations API** has structured file:line output that the raw log doesn't:

\```bash
CHECK_RUN=$(gh api "repos/I-SOFT-Mionica/isoft-fm-foundation/commits/$HEAD_SHA/check-runs" \
  --jq '.check_runs[] | select(.name == "phpcs") | .id')
gh api "repos/I-SOFT-Mionica/isoft-fm-foundation/check-runs/$CHECK_RUN/annotations" \
  --jq '.[] | "\(.path):\(.start_line) \(.message)"' | grep -v "Node.js 20"
\```

PHPUnit annotations are sparse — use `gh run view <run_id> --log-failed | tail -60` for assertion failure detail. See `qa` skill for parsing PHPUnit output.

## Version bump procedure

Three locations must move together:

1. `isoft-fm-foundation.php` — `* Version:` header
2. `isoft-fm-foundation.php` — `const ISOFT_FMF_VERSION = '...'` directly below
3. `readme.txt` — `Stable tag:` line

Plus two changelog entries:

- `CHANGELOG.md` — `## [X.Y.Z] — YYYY-MM-DD` block, developer-facing prose
- `readme.txt` `== Changelog ==` — `= X.Y.Z =` block, user-facing summary

Bump rules:

- **Patch (`0.10.0` → `0.10.1`)** — fix-only, no API surface change
- **Minor (`0.10.x` → `0.11.0`)** — new features, optional behaviour changes
- **Major (`0.x.y` → `1.0.0`)** — only when graduating from pre-1.0

## Branch → PR → merge flow

\```bash
git checkout main && git pull --ff-only
git checkout -b <descriptive-name>
# ... edit, commit (Co-Authored-By: Claude on agent commits) ...
git push -u origin <branch>
gh pr create --base main --head <branch> --title "..." --body "..."
# poll CI with the loop above
# on failure: focused fix commits, don't rebase/squash — merge handles squash
gh pr merge <num> --squash --delete-branch
git checkout main && git pull --ff-only
\```

For releases: after the merge, `cd .. && python build.py` writes the new zip.

## WordPress.org SVN deploy

Manual trigger via **Actions → Deploy to WordPress.org → Run workflow**:

- Branch: `main` (always)
- Version: the released version (must match all three version locations)
- Dry run: check it the first time you test the pipeline; leave unchecked for real deploys

The workflow uses `10up/action-wordpress-plugin-deploy@stable`, which:

1. Stages the repo into SVN `trunk/` (respects `.distignore`)
2. `svn copy trunk tags/X.Y.Z`
3. Syncs `.wordpress-org/` to SVN `/assets/`
4. `svn ci`

WordPress.org's crawler picks new versions up within ~15 minutes. **The `Stable tag` line in `readme.txt` decides which SVN tag WP.org actually serves** — bumping the plugin header alone is not enough. The workflow's pre-flight rejects mismatched versions before any SVN operation.

Secrets required in repo settings (one-time):

- `SVN_USERNAME` — wp.org login (case-sensitive)
- `SVN_PASSWORD` — application password from <https://profiles.wordpress.org/me/profile/edit/group/3/?screen=svn-password> (not the wp.org login password)

## `.wordpress-org/` asset specs

Files in this directory (NOT in the plugin zip — separate SVN namespace):

| File | Dimensions | Where it shows |
|---|---|---|
| `banner-772x250.png` (or `.jpg`) | 772×250 | Top of the WP.org plugin page |
| `banner-1544x500.png` (or `.jpg`) | 1544×500 | 2× retina banner |
| `icon-128x128.png` (or `.svg`) | 128×128 | Plugin card in WP admin → Add New |
| `icon-256x256.png` | 256×256 | 2× retina icon |
| `screenshot-1.png` … `screenshot-N.png` | any | Match order with `readme.txt` `== Screenshots ==` |

Filenames are case-sensitive. WP.org caches assets — allow ~15 min after deploy for changes to surface.

## Post-merge hygiene

After every merge:

\```bash
git checkout main && git pull --ff-only
git branch --merged | grep -v '\\*\\|main' | xargs -r git branch -d
\```

The remote branch was already deleted by `gh pr merge --delete-branch`. The local cleanup is manual.

## Cross-cutting

- Test failure debugging → `qa` skill
- PHP / WPCS quirks behind a failure → `wp-conventions` skill
- Security regression checks before deploy → `security` skill
