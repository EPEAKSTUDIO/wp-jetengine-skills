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
is a 4-step procedure for this. Every bug found so far in this repo's runnable suites was
diagnosed this way (see `docs/audit-2026-07-16.md`'s "Fourth round"/"Fifth round"
sections for the worked examples — wrong accessors, an overly-strict assertion, and one
genuine site-crashing plugin landmine).

**Is `TEST-REGIMEN.md` still worth keeping once a skill has a `tests.php`?** Yes, but
treat it as *the residual after automation*, not a parallel copy. `tests.php` is the
automatable subset — anything assertable from PHP inside one request. `TEST-REGIMEN.md`
should be trimmed down to whatever `tests.php` still can't cover: a real form submission
through the browser, a page-builder/editor-UI visual check, a fixture that would create
side effects if re-run automatically (e.g. `jetengine-mcp-tools`' Test 4 — live-creating
a CPT/taxonomy/meta-box/listing/glossary every run isn't safe to automate), or a claim
that genuinely needs human judgment. Once every runnable suite in this repo was added,
each skill's `TEST-REGIMEN.md` got a "Run log" section added at the top noting exactly
which of its manually-written tests got automated/superseded and which remain open for
that reason — follow that same pattern for a new plugin's skills rather than duplicating
the whole checklist into `tests.php`'s code comments.

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
  deactivate it**, everything else (ids 23, 24, 27-34 as of this writing) are per-skill
  suites that depend on it. Id 25 is a deactivated crash-reproduction diagnostic —
  documented on purpose, do not activate it or hit its route.
- Plugins installed on the sandbox as of 2026-07-16: JetEngine, JetFormBuilder,
  JetSmartFilters. No other Crocoblock plugins yet (see "Adding a new Crocoblock
  plugin" below for what that means going forward).

## What's done vs. still open

**All 11 skills now have runnable `tests.php` suites, all currently green:**
`jetengine-query-builder` (4/4), `jetsmartfilters-query` (5/5), `jetengine-modules`
(6/6), `jetformbuilder-fields` (7/7), `jetengine-cct-internals` (3/3),
`jetengine-relations` (6/6), `jetengine-mcp-tools` (4/4), `jetengine-listings-macros`
(5/5), `jetformbuilder-actions` (3/3), `jetformbuilder-hooks` (3/3) — that's 10; the
11th, `jetengine-router`, is a dispatch-only skill with no independent claims to test.
46/46 assertions passing as of 2026-07-16. The 2026-07-16 round that added the last 6
suites found zero plugin bugs but did surface two documentation additions (not
corrections): `tool-add-query`'s stored rows carry undocumented `_id`/`collapsed`/`type`
keys (`jetengine-mcp-tools`), and pipe-arg macro syntax (`%macro|foo,bar%`) is silently
dropped unless the macro class declares a matching `macros_args()` schema
(`jetengine-listings-macros`) — both now folded into their `SKILL.md`s.

**2026-07-16, second round (dev-docs + Codelab audit — see below for detail): 7 of those
10 suites gained one new test each** (`jetsmartfilters-query` 7/7, `jetengine-modules`
7/7, `jetformbuilder-fields` 8/8, `jetengine-cct-internals` 4/4, `jetengine-listings-macros`
6/6, `jetformbuilder-actions` 4/4, `jetformbuilder-hooks` 4/4) — **54/54 assertions
passing across all 10 suites** as of this writing, one real fix needed along the way
(`jfb-8` initially fataled on a missing `set_context()` call, fixed same session).

Two smaller, self-contained gaps remain: `jetengine-modules`' Dynamic Visibility/Data
Stores tests only check module-gating right now because neither module is activated on
the sandbox (see that skill's `TEST-REGIMEN.md`); and several suites (`jetengine-modules`,
`jetformbuilder-fields`, `jetengine-mcp-tools`'s Test 4, `jetformbuilder-hooks`'
Test 1/2/5, `jetformbuilder-actions`' Test 2/5) mostly test reachability/direct-invocation
rather than full fixture-driven pipelines — real meta-box field groups, options pages,
and end-to-end form submissions with repeaters/Conditions still need a browser or a
one-off manual run, not something safe to bake into an auto-repeatable suite. Each
affected skill's `TEST-REGIMEN.md` "Run log" section says exactly which of its original
tests remain open for this reason.

Full prioritized backlog (new skills to write, deeper gaps within existing ones):
`docs/audit-2026-07-16.md`'s "Backlog" section at the bottom.

## 2026-07-16, second round: dev-docs + Codelab audit

Read the official Crocoblock `developer-documentation` GitHub repo (cloned to
`C:\tmp\dev-docs`, not committed here) and crawled crocoblock.com/codelab (real
customer-built snippets) via two background research agents, diffed both against all 10
existing skills, then verified every finding against locally-checked-out plugin source
(`plugins/jet-engine`, `plugins/jetformbuilder`, `plugins/jet-smart-filters` — gitignored,
not committed) before writing anything down — one codelab-sourced claim
(`jet-engine/meta-fields/field-options`'s arg count, copied from a snippet that itself
registered it wrong) was corrected from 2 args to the real 3 during this process, exactly
the kind of error this repo's "verify against source, not just an external doc" principle
exists to catch.

**Landed this round** (all live-verified via `tests.php`, deployed/re-run against
`jackfruit.epeak.studio`, all green — 47 new assertions across 7 suites, 0 failures after
one fix — see each skill's `TEST-REGIMEN.md` "Run log" addendum for specifics):
- `jetformbuilder-actions`: the Action Conditions system (`jet-form-builder/register/action-condition-settings`
  + `jet-form-builder/actions/process-condition`) — previously undocumented in any JFB skill.
- `jetformbuilder-hooks`: the `jet-form-builder/default-process-event/executors` filter
  and a first concrete lead into the still-uninventoried Payment Gateways module.
- `jetformbuilder-fields`: the media-field guest-upload gate
  (`jet-form-builder/media-field/before-upload` + `Parser_Context::allow_for_guest()`).
- `jetengine-listings-macros`: the "subclass a built-in macro class" pattern (distinct
  from subclassing the abstract base), worked via `Query_Results_Macro`.
- `jetengine-cct-internals`: the CSV export value/separator filters.
- `jetengine-modules`: the Meta Boxes custom Options Source two-filter pairing.
- `jetsmartfilters-query`: worked `final-query` examples (range-splitting, `|search`
  suffix stripping) and a newly-documented cross-plugin hook,
  `jet-engine/query-builder/filters/before-after-props`.

One claim was checked and found **not** to be a contradiction despite looking like one at
first pass: the dev-docs' own example for `jet-form-builder/action/after-post-*` registers
with `add_action(..., 10, 2)`, but the real source (`base-post-action.php:37-42`) fires it
with 3 args — `jetformbuilder-hooks`' existing claim was already correct; the dev-docs
example just doesn't request the 3rd arg (harmless, WP allows that).

**Deliberately not attempted this round** (scoped out for time, not forgotten — see
`docs/audit-2026-07-16.md`-style backlog below): three entirely new, sizeable JetEngine/
JFB subsystems the audit surfaced with no owning skill yet —
- **JetFormBuilder Payment Gateways module** (`modules/gateways/*`) — PayPal/Stripe
  checkout, its own scenario/executor classes and DB-backed payment records. Both
  `jetformbuilder-fields` and `jetformbuilder-hooks` now flag concrete entry points
  (`get_gateways()`, the executors filter) but the module itself is unexplored.
- **JetFormBuilder's own macro-filter system** (`%field|filter(args)%`,
  `jet-form-builder/content-filters`) — a separate implementation from JetEngine's
  `%macro%` engine with confusingly similar syntax; ~12 built-in filters, zero coverage.
- **JetEngine Profile Builder module** and **REST API Listings module** — both real,
  both undocumented anywhere in this repo.
Also out of scope this round: the 4 plugins dev-docs covers that aren't installed on the
sandbox yet (JetPopup, JetBooking, JetWooProductGallery, JetCompareWishlist) — noted for
future "adding a new Crocoblock plugin" work, no source/sandbox access attempted.

## 2026-07-16, third round: full Crocoblock GitHub Gists audit (305 gists)

Follow-up to the second round above, prompted by a direct question: the Codelab pass
had only sampled ~16 of ~126 snippets (via a 403-workaround against the marketing site).
Crocoblock's GitHub Gists account (`https://gist.github.com/Crocoblock`) turned out to be
the actual raw source those Codelab pages embed, and a far more complete/reliable one —
**305 public gists total**, fetched via `api.github.com/users/Crocoblock/gists` (clean,
paginated, no 403). Six parallel background research agents fetched and read the real
code (not just descriptions) for all 305, classifying each as JetEngine/JetFormBuilder/
JetSmartFilters-relevant (**~230 gists**) or belonging to a different Crocoblock plugin
(**~75 gists** — JetBooking/JetAppointments, JetPopup, JetWooBuilder, JetReviews,
JetSearch, JetElements, JetBlog, JetThemeCore, JetTabs, JetCompareWishlist, etc.).

The out-of-scope ~75 are logged with gist URLs + key hook/class names in
`.claude/skills/_other-plugins-backlog/OTHER-PLUGINS.md` (not a Claude Code Skill itself
— a research log for whoever builds out the next plugin's skill; JetBooking/
JetAppointments is flagged as the highest-priority next candidate, ~25 gists including a
full JS API reference doc already gisted).

Every candidate finding from the ~230 relevant gists was verified against the locally
checked-out plugin source (same `plugins/jet-engine`/`jetformbuilder`/`jet-smart-filters`
checkouts as the second round) before being written down or tested — this caught two
real mistakes before they shipped:
- A `SKILL.md` draft for `jetformbuilder-actions`' new "custom Insert/Update Post object
  property" section initially wrote `$properties->push( new My_Property() )`, copying
  the shape of similar collection APIs — but `Object_Properties_Collection`/`Collection`
  has no `push()` method; the real one is `add()` (confirmed at
  `includes/classes/arrayable/collection.php:77`). Fixed before ever deploying a test.
- `jetengine-query-builder`'s new `qb-5` test assumed `after-query-setup` would re-fire
  on every `get_items()` call (like `query/items` genuinely does) — it doesn't;
  `Manager::get_query_by_id()` returns an already-constructed, cached query object, and
  `after-query-setup` only ever fires once, at construction time on `init`. Caught by an
  actual failing assertion on first run, not by re-reading source more carefully after
  the fact — split into a live test (query/items) and a source/precondition check
  (after-query-setup) once the real timing was understood.

**Landed this round** (all live-verified via `tests.php`, deployed/re-run against
`jackfruit.epeak.studio`, all green — 52 assertions across 7 suites, 1 failure caught
and fixed same-session — see each skill's `TEST-REGIMEN.md` "second addendum" for
specifics):
- `jetengine-cct-internals`: `user-has-access`, `item-to-update`, `factory/raw-fields`,
  `admin-columns` filters (gating writes, reshaping schema, rewriting a pending write).
- `jetengine-relations`: `jet-engine/relations/raw-relations` (a real, previously-missed
  way to register a relation's *config* without the admin UI — corrects the earlier
  "no `register_relation()` helper" claim), `relation/update/before`+`/after` hooks, the
  Sources system (`sources-list`/`object-id-by-source/{key}`), and a custom post-picker
  items filter for the "Connect Relation Items" UI.
- `jetengine-modules`: Data Stores' AJAX-only hook gotcha (before/after add/remove hooks
  only fire from `ajax_add_to_store()`/`ajax_remove_from_store()`, not a direct
  `add_to_store()` call) plus its post-count hooks, programmatic Options Page
  registration (`register_new_options_page()`), and a new Maps Listings section
  (geocode providers, a real naming inconsistency between `maps-listing` singular vs.
  `maps-listings` plural hook prefixes) and Profile Builder section.
- `jetengine-query-builder`: the two most commonly-used real-world hooks,
  `after-query-setup` and `query/items` (previously undocumented despite being far more
  common in the wild than the registration hooks this skill already covered).
- `jetengine-listings-macros`: the custom-"context" two-filter pairing
  (`allowed-context-list` + `object-by-context/{key}`) — same shape as the Options
  Source pairing in `jetengine-modules`, used by several independent real snippets to add
  contexts like "post parent" / "post featured image" / "previous object in stack".
- `jetsmartfilters-query`: five more filters (`filter-instance/args`,
  `filters/filter-options`, `range/source-callbacks`, `query/meta-query-row`,
  `post-type/meta-fields-settings`) and the front-end JS event bus
  (`JetSmartFilters.events.subscribe()`, real channel names including a genuine "fiter"
  typo baked into the shipped JS).
- `jetformbuilder-actions`: the `post-modifier/object-properties` extension point for
  the Insert/Update Post action — confirmed as a real, common pattern (custom Post Slug/
  Password/Menu-Order/Scheduled-Date properties) across many independent snippets.

**Deliberately not attempted this round** (scope decision, not an oversight — flagged for
a future pass): `jetformbuilder-hooks`, `jetformbuilder-fields`, `jetengine-mcp-tools`,
and `jetengine-router` had comparatively few new findings in the ~230-gist set and were
left untouched this round to keep the verify-then-test discipline tight rather than
spreading thinner across more files. A non-exhaustive list of findings not yet folded in
anywhere (mostly one-off widget/field tweaks, lower value than what's listed above):
JFB gateways `before-create`/`response`/`request-args` (Stripe/webhook), `capability/
form`, `form-action-url`, `form-refer-url`, `before-start-form`/`after-end-form`,
`preset-sanitize`/`editor/preset-config`, `content-filters` (`Base_Filter` class for
Send-Email macros), per-field-type `render/{type}-field/attributes` filters, JetEngine
Charts Builder (a hook name was seen in a gist but couldn't be verified — the add-on's
source isn't in this repo's local plugin checkout, so it was deliberately **not**
documented per this repo's verify-before-writing principle), and QR Code module details
beyond the one function already noted in `jetengine-listings-macros`.

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

**A third lesson from the 2026-07-16 round (`jetformbuilder-actions`/`jetformbuilder-hooks`
suites): a real end-to-end form/HTTP submission is often NOT actually required to test a
"submission lifecycle" claim.** Before assuming a suite needs a live browser session or a
throwaway form + curl POST (the pattern the original manual `TEST-REGIMEN.md` runs used),
check whether the class the claim is about is directly instantiable/callable within a
single PHP request — e.g. `Action_Exception`/`Status_Info`'s success/status wiring, or
`Call_Hook_Action::do_action()` (its `$settings` property is public, so it can be set and
invoked directly with a fake `$request` array), don't need any submission at all.
Reserve "needs a real submission" for claims that genuinely depend on the full request
lifecycle (firing order across multiple hooks, Condition-gating, DB record timing) — and
say so explicitly in `TEST-REGIMEN.md` when a claim is left unautomated for that reason,
rather than leaving it silently unaddressed.
