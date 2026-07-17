# Runnable test-suite convention

`docs/test-regimen-guide.md` describes `TEST-REGIMEN.md` — a prose checklist a human
or agent works through by hand. This doc describes the **runnable** counterpart: a
small shared harness plus one machine-checkable PHP suite per skill, deployed as Code
Snippets snippets, that returns structured pass/fail JSON over REST. It exists so a
future agent auditing this repo can re-run every claim with one `curl` instead of
re-deriving test code from scratch, and can immediately tell "the plugin behavior
changed" from "this test itself has a bug."

## Why not just keep writing one-off probe snippets

The first two audit rounds in this repo worked by
writing a throwaway snippet per investigation, reading `error_log()` output or a
one-shot REST endpoint, then discarding the reasoning once it was written up in
`SKILL.md`. That's fine for a single investigation, but it doesn't scale:

- Nothing is re-runnable — a future agent re-auditing this repo has to reinvent the
  same probe code from scratch, with no way to know if today's plugin update broke
  something that used to be true.
- Pass/fail was implicit in prose ("Actual result: ..., PASS"), not machine-readable.
- Nothing distinguished "the assertion failed because the plugin behaves differently
  now" from "the assertion failed because the test fixture/code has a bug" — both look
  like a wall of red the same way.

## The shape: one shared harness + one suite snippet per skill

**`test-harness/core-snippet.php`** (this repo file is the source of truth) — deploy
this **once** as a permanently-active snippet named exactly
`AGENT-TEST-CORE harness (keep active, do not delete)`. It defines:

- `agent_test_assert( $suite, $id, $claim, $pass, $expected, $actual, $notes )` — call
  this once per assertion from inside a suite. You compute `$pass` yourself (a plain
  bool) — the harness doesn't try to do generic value comparison, since JetEngine/JFB/
  JSF return values are too varied (rows, WP_Error, bare scalars, arrays) for one
  comparator to handle sensibly. `$expected`/`$actual` are the raw values for a human
  or future agent to eyeball on a failure — always populate both, never just the bool.
- `agent_test_run_suite( $suite )` — fires `do_action("agent-test/run-suite/{$suite}")`
  inside a try/catch, collects everything the suite asserted, and returns
  `{suite, run_at, fatal_error, summary: {total, passed, failed}, results: [...]}`.
  **`fatal_error` is not null only when the suite itself threw** (missing fixture,
  renamed class, typo) — check this before reading `results`, since a fatal error means
  none of that suite's assertions ran at all, not that they all failed.
- Two REST routes: `GET /agent-test/v1/suite/{suite}` (runs it, returns the JSON above)
  and `GET /agent-test/v1/suites` (lists every currently-registered/active suite).

**One suite snippet per skill**, e.g. `.claude/skills/jetengine-query-builder/tests.php`
(this repo file is the source of truth for that suite). Deploy it as its own snippet
named `AGENT-TEST-SUITE: <skill-slug>`. Its whole body is:

```php
add_action( 'agent-test/run-suite/jetengine-query-builder', function() {
    // one agent_test_assert(...) call per claim being tested
} );
```

Suites can be active or inactive independently — a suite only needs to be active while
you're running it (or permanently, if `docs/code-snippets-rest-api.md`'s finding #7
about unreliable activate/deactivate responses makes that fiddly — re-`GET` to
confirm either way).

## Writing a suite: conventions

- **One `agent_test_assert()` call per distinct claim**, not one giant assertion per
  test — a future agent scanning `results` for the first `pass: false` needs each
  entry to point at exactly one sentence in `SKILL.md`.
- **`$id` is a short stable slug** (`qb-1`, `qb-2`, ...), not a description — descriptions
  go in `$claim`. Keep ids stable across edits so a diff between two runs is meaningful.
- **`$notes` always carries the file:line citation** the claim came from in the plugin
  source (copy it straight from `SKILL.md`) — this is what lets a future agent
  re-ground-truth a FAIL against source without re-deriving it.
- **Wrap risky calls in try/catch inside the suite itself**, not just relying on the
  harness's outer catch — an exception from test #2 shouldn't prevent tests #3-10 in
  the same suite from running. On catch, still call `agent_test_assert()` with
  `$pass = false` and put the exception message in `$actual`, so the failure shows up
  as a normal result row instead of only in `fatal_error`.
- **Suites must be safe to re-run** — use fixed, clearly `AGENT-TEST`-namespaced
  fixture IDs/slugs (reuse the ones already listed in
  `docs/code-snippets-rest-api.md`'s inventory where possible) and prefer
  idempotent operations. If a test necessarily mutates state (e.g. inserts a row),
  clean up at the end of that same test's callback, or clearly document in `$notes`
  that it's cumulative and what state it leaves behind.
- **A suite is not required to cover every method in a skill on day one** — partial
  coverage that's actually running beats a complete plan that isn't. Add tests
  incrementally; each new `agent_test_assert()` call is a small, independent addition.

## Reading results: is the plugin wrong, or is the test wrong?

When `curl`-ing `/agent-test/v1/suite/{suite}` turns up a `pass: false`:

1. Check `fatal_error` first — non-null means the suite broke before finishing, not
   that a specific claim is false.
2. Read that result's `expected`/`actual`/`notes` together. `notes` has the file:line
   citation — go re-read that exact line in `plugins/.../*.php`.
3. If the source still clearly says what `SKILL.md` claims, the **test** is likely
   wrong (bad fixture id, wrong method call, stale assumption about a global) — fix
   `tests.php`, not `SKILL.md`.
4. If the source has changed (plugin version bump) or was misread originally, the
   **skill claim** is wrong — fix `SKILL.md` and its `TEST-REGIMEN.md` entry, and note
   the plugin version discrepancy.

## Keeping `TEST-REGIMEN.md` and `tests.php` in sync

`TEST-REGIMEN.md` stays as the human-readable narrative (why the test exists, what
fixture it needs, what a pass/fail *means*). `tests.php` is the runnable version of the
same claims. When you add a test to one, add the matching entry to the other — a
`TEST-REGIMEN.md` entry with no `tests.php` counterpart should say so explicitly
("not yet automated — manual steps only") rather than silently going stale.

## Deploying / updating a suite snippet

Same POST/PUT flow as any Code Snippets snippet (see
`docs/code-snippets-rest-api.md`) — paste the suite file's contents into the `code`
field (Code Snippets strips a leading `<?php` automatically, so `tests.php` files here
keep it for readability as a real PHP file, matching how the harness core file does).
After deploying or editing, re-`GET /agent-test/v1/suites` to confirm the suite
registered, then `GET /agent-test/v1/suite/{suite}` to run it.
