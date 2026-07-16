# Handoff — read this first

This file is the up-to-date "what's the state of things" doc for whoever (human or
agent) picks this repo up next. `docs/audit-2026-07-16.md` is a valuable historical log
of how coverage grew round by round, but it's a journal, not a status board — trust
this file over it for "what's true right now."

## What this repo is

A collection of Claude Code Skills (`.claude/skills/*/SKILL.md`) documenting verified,
non-obvious JetEngine/JetFormBuilder/JetSmartFilters (Crocoblock) internals — the kind
of thing an agent would otherwise hallucinate a plausible-sounding wrong function name
for. See `README.md` for the full skill list and `docs/principles.md` /
`docs/authoring-guide.md` for how a `SKILL.md` gets written.

## The two-layer verification system

Every skill has (or should have) two companion files next to its `SKILL.md`:

1. **`TEST-REGIMEN.md`** — a human-readable prose checklist: what to set up, what to
   trigger, what to observe, what counts as pass/fail. See
   `docs/test-regimen-guide.md`.
2. **`tests.php`** — the *runnable* version of the same claims, deployed as a WordPress
   Code Snippets snippet, returning structured JSON over REST. See
   `docs/test-harness-guide.md` — **read this before writing or running any test**, it
   explains the whole convention and is not optional context.

The short version: one shared, permanently-active "core" snippet
(`test-harness/core-snippet.php`) provides `agent_test_assert()` and two REST routes;
each skill's `tests.php` registers one `add_action('agent-test/run-suite/{skill}', ...)`
callback with one `agent_test_assert()` call per claim. Run any suite with:

```
GET https://<sandbox>/wp-json/agent-test/v1/suite/{skill-slug}
Authorization: Bearer <jwt>
```

Response shape: `{suite, run_at, fatal_error, summary: {total, passed, failed}, results: [...]}`.
**`fatal_error` non-null means the suite itself broke** (bad fixture, renamed class, typo)
— not that a claim is false. Check it before reading `results`. Each result has
`{id, claim, pass, expected, actual, notes}` — `notes` always carries a file:line
citation back into the plugin source, specifically so you don't have to re-derive where
a claim came from when triaging a failure.

**When a test fails, work out whether the *test* is wrong or the *plugin behavior* is
wrong before touching anything** — `docs/test-harness-guide.md`'s "Reading results" section
is a 4-step procedure for this. Every bug found so far in this repo's four runnable
suites was diagnosed this way (see `docs/audit-2026-07-16.md`'s "Fourth round"/"Fifth
round" sections for the worked examples — wrong accessors, an overly-strict assertion,
and one genuine site-crashing plugin landmine).

## Current sandbox state (jackfruit.epeak.studio)

- Not production — the site owner confirmed it's fine to create/test freely here, as
  long as artifacts are `AGENT-TEST`/`ZZZ-*`-namespaced and kept (not deleted — this
  plugin's `DELETE` endpoint doesn't reliably work anyway, see
  `docs/code-snippets-rest-api.md` finding #6).
- Auth: JWT bearer token, currently stored in `.mcp.json` (gitignored, not committed) —
  **never** print it, echo it to a terminal that gets logged, or write it to a file;
  extract it into a shell variable inline when you need it. If a future session's
  `.mcp.json` token has expired, you'll need the site owner to reissue one — there's no
  self-service refresh flow documented here.
- Code Snippets ids currently deployed and their purpose are fully inventoried in
  `docs/code-snippets-rest-api.md` — **id 22 is the shared harness core; never
  deactivate it**, everything else (ids 23, 24, 27, 28 as of this writing) are per-skill
  suites that depend on it. Id 25 is a deactivated crash-reproduction diagnostic —
  documented on purpose, do not activate it or hit its route.
- Plugins installed on the sandbox as of 2026-07-16: JetEngine, JetFormBuilder,
  JetSmartFilters. No other Crocoblock plugins yet (see "Adding a new Crocoblock
  plugin" below for what that means going forward).

## What's done vs. still open

**Runnable `tests.php` suites exist for 4 of 11 skills**: `jetengine-query-builder`
(4/4 pass), `jetsmartfilters-query` (5/5 pass), `jetengine-modules` (6/6 pass),
`jetformbuilder-fields` (7/7 pass). All four are currently green on the sandbox.

**Still pre-harness (no `tests.php` yet)**, verified only with one-off probe snippets
before this convention existed: `jetengine-cct-internals`, `jetengine-relations`,
`jetengine-mcp-tools`, `jetengine-listings-macros`, `jetformbuilder-actions`,
`jetformbuilder-hooks`. Retrofitting these is the single most valuable next chunk of
work — each one already has real fixtures documented in its `TEST-REGIMEN.md` and prior
probe-snippet history in `docs/code-snippets-rest-api.md`'s inventory, so writing the
`tests.php` version is mostly translating existing verified claims into
`agent_test_assert()` calls, not re-investigating from scratch.

Two smaller, self-contained gaps: `jetengine-modules`' Dynamic Visibility/Data Stores
tests only check module-gating right now because neither module is activated on the
sandbox (see that skill's `TEST-REGIMEN.md`); and `jetengine-modules`/`jetformbuilder-fields`
mostly test reachability rather than full fixture-driven pipelines (real meta-box field
groups, options pages, form submissions with repeaters don't exist yet).

Full prioritized backlog (new skills to write, deeper gaps within existing ones):
`docs/audit-2026-07-16.md`'s "Backlog" section at the bottom.

## Adding a new Crocoblock plugin (JetPopup, JetWooBuilder, JetStyleManager, etc.)

Nothing plugin-specific in this repo's *process* is JetEngine-only — the same loop
applies to any Crocoblock plugin:

1. Get the plugin's source into `plugins/<slug>/` (gitignored, not committed — see
   `.gitignore`) and install it on the sandbox if live-testing is in scope.
2. Read source for the subsystem in question, write a `SKILL.md` draft citing
   file:line for every claim — see `docs/authoring-guide.md` for the format and
   `docs/principles.md` for what makes a claim worth including (the "an agent would
   plausibly hallucinate the wrong name here" bar, not "documents everything").
3. Write `TEST-REGIMEN.md` alongside it (prose checklist) **and** `tests.php` (runnable
   suite) together, not one then the other later — `docs/test-harness-guide.md` has the
   exact shape to copy. Keep them in sync; if a `TEST-REGIMEN.md` entry has no
   `tests.php` counterpart, say so explicitly rather than letting it silently drift.
4. Deploy the suite to Code Snippets (see `docs/code-snippets-rest-api.md` for the REST
   mechanics — UTF-8 byte-encoding gotcha and all), run it, and **actually fix what it
   finds** before calling the skill done. Every suite in this repo so far has caught at
   least one real bug on first run — expect the same.
5. Add the new suite's snippet id to `docs/code-snippets-rest-api.md`'s inventory, add
   the skill to `README.md`'s list and `jetengine-router`'s dispatch table, and update
   this file's "what's done vs. still open" section.

**The one hard-won safety lesson to carry forward**: before calling any constructor or
static method you haven't seen called elsewhere in the plugin, check whether a singleton
already owns that responsibility (`grep` for `::instance()` / existing `new ClassName()`
call sites first). This repo hit one genuine site-crashing landmine
(`jetsmartfilters-query`'s `Storage\Controller` — see `docs/audit-2026-07-16.md`'s
"Fourth round") from directly instantiating a class whose singleton wrapper already
constructs one on every request, triggering an uncatchable "Cannot redeclare class"
fatal that a `try/catch(\Throwable)` cannot stop. If you're not sure a class is safe to
instantiate directly, grep for its constructor's body first, and consider isolating a
suspicious call in its own throwaway diagnostic snippet (kept, deactivated, documented —
like ids 25/26 in the inventory) before wiring it into a real suite.

Also worth naming for a new plugin specifically: **check for module/feature gating
before writing a reachability test** (`jetengine-modules`' `mod-4`/`mod-5` learned this
the easy way — Dynamic Visibility and Data Stores are optional modules, so
`class_exists()` alone can't tell you "broken" from "just not turned on"; check the
plugin's own activation-state accessor, if one exists, and assert on consistency between
the two rather than assuming the class is always loaded).
