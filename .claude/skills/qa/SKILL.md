---
name: qa
description: PHPUnit conventions for this codebase, the WP-testcase traps we've hit, deciding what's unit-testable vs integration-only. Load when writing tests, debugging a PHPUnit failure, or before claiming "I added test coverage".
---

# QA playbook

## Test class skeleton

\```php
class FooTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        // ... fixtures (per-test; rolled back in tear_down)
    }

    public function test_some_behaviour(): void {
        // arrange / act / assert
    }
}
\```

File at `tests/FooTest.php`. Auto-discovered by `phpunit.xml.dist`. Test method names start with `test_`.

## Running locally

\```bash
vendor/bin/phpunit                       # full suite
vendor/bin/phpunit --filter FooTest      # one class
vendor/bin/phpunit --filter test_x       # one method, across all classes
\```

Needs PHP 8.4 and `tests/wp-tests-config.php` (copy from `wp-tests-config-sample.php` and fill DB creds). Local PHP is often 8.2 or 8.3 — when that's the case, push to CI and use the workflow run as your test runner.

## Fixture patterns

Use the WP test factory for posts, terms, users — it handles edge cases that plain `wp_insert_post()` doesn't:

\```php
$id = self::factory()->post->create( array(
    'post_type'     => 'isoft_fmf_file',
    'post_status'   => 'publish',
    'post_title'    => 'Foo',
    'post_date'     => $date,
    'post_date_gmt' => $date,  // both required if the test cares about date
) );

$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
wp_set_current_user( $user_id );
\```

For our domain objects, prefer the helpers:

\```php
$id = (int) isoft_fmf_create_draft_download( array( 'title' => '...' ) );
\```

Returns an `int` post ID, draft state, correct post type. Pass `'category' => $term_id` to assign.

For category terms:

\```php
$term = wp_insert_term( 'Reports', 'isoft_fmf_category', array( 'slug' => 'reports' ) );
$term_id = (int) $term['term_id'];
\```

`wp_insert_term` returns `array{ term_id: int, term_taxonomy_id: int }` on success, `WP_Error` on failure.

## Known traps

### `wp_update_post` silently ignores `post_date`

Updating an existing post with `wp_update_post( array( 'ID' => $id, 'post_date' => $date ) )` keeps the **original** date unless you also pass `'edit_date' => true`. The cleaner fix is to create the post with the date up front via the factory.

Hit during 0.10.0 `FeaturedTest` — every test post ended up with near-identical timestamps and the secondary date sort was indeterminate. Lesson: test data that depends on `post_date` ordering must use the factory pattern above.

### `set_object_terms` and other shared-hook signature mismatches

If your test fires `do_action( 'edited_isoft_fmf_category', $term_id )`, every listener on that action runs — including unrelated ones like `ISOFT_FMF_Category_Folders::on_edited()` which expects two arguments. Result: `ArgumentCountError`.

Test the **behaviour of your handler** directly:

\```php
$this->access->on_category_edited( $cat );  // not do_action(...)
\```

The unit-test boundary is your method, not the WP action fan-out.

### Test transaction visibility

`WP_UnitTestCase` wraps each test in a DB transaction that's rolled back in `tear_down`. Test data is invisible to processes outside the test (e.g., direct `mysql` shell during debug). Inspect from within the test method.

### Effective-role meta isn't populated automatically

When testing access control, `_isoft_fmf_effective_access_role` is only set by hooks (`save_post`, `set_object_terms`, etc.) — not by raw `update_post_meta`. After building fixtures, call:

\```php
$access->recompute_effective_role( $id );
\```

…explicitly, so the value the SQL filter joins against actually exists. See `CategoryAclTest::make_download` for the canonical pattern.

## What's unit-testable vs integration-only

**Unit-testable now (write tests for these):**

- CRUD on custom-table managers (`File_Manager`, `License_Manager`, `Download_Logger`)
- Helpers in `functions.php` (slugs, paths, mime classes, URL builders, UA blocklist)
- Access control resolution (`recompute_effective_role`, `can_access_download`, `user_meets_role`, `get_accessible_role_values`)
- Query-clause filters (`Featured::prepend_featured_sort`, `Access_Control::add_access_clauses`)
- Activation (table creation, capabilities, migrations)
- File integrity scan logic

**Integration-only (skip unit; rely on manual + Plugin Check):**

- `ISOFT_FMF_Download_Handler::handle()` — calls `exit`, needs real HTTP request
- `ISOFT_FMF_Bundle_Handler::stream_bundle()` — same, plus needs files on disk
- REST API endpoints — would need REST request fixtures (doable but invasive; not worth it pre-1.0)
- Admin AJAX handlers — same
- Block render — server-render output needs a real Gutenberg context
- File upload via `wp_handle_upload` — needs `$_FILES` fixture + real temp file

When skipping integration coverage, leave a comment in the relevant class noting "manual-test only" and what to verify.

## Reading CI failure logs

PHPUnit failure format in `gh run view --log-failed`:

\```
1) FooTest::test_some_thing
Failed asserting that 1 is identical to 2.

/path/to/FooTest.php:42
\```

The file:line tells you WHERE; the message tells you WHAT failed. For complex assertion messages like `Failed asserting that 1 is less than 0`, trace to the test source — the assertion is usually `assertLessThan( $expected, $actual )` so the message reads "**actual** is less than **expected**".

For PHPCS (which runs in the WPCS workflow), the structured annotations API has file:line output that the log doesn't surface — see `cicd` skill for the gh API pattern.

## Don't write tests for

- The framework itself (don't assert `update_post_meta` works)
- Trivial getters / setters with no logic
- Configuration constants
- Glue code that just calls a tested function

Tests should encode **behavioural decisions** — sort order, access resolution, sanitisation, error responses. Tests for boilerplate add maintenance cost without catching real bugs.

## When CI is green but the feature still seems off

PHPUnit passing means the **unit boundary** behaves. It doesn't mean:

- The full HTTP request through `template_redirect` works
- The block renders correctly in a real editor
- The admin AJAX endpoint returns useful errors

For 0.10.0+ features that are mostly integration paths (ZIP bundle, agreement modal, frontend filters), set up a Local install + manual checklist and walk through them after each release. CI is necessary but not sufficient.

## Cross-cutting

- CI workflow specifics (how to read failures) → `cicd` skill
- Security-regression test patterns → `security` skill
- Frontend integration testing (mostly manual) → `frontend` skill
