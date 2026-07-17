# Handoff — read this first

This file is the up-to-date "what's the state of things" doc for whoever (human or
agent) picks this repo up next. It's a running journal of how coverage grew round by
round — trust the most recent section for "what's true right now," and see
`docs/known-gaps.md` for a short, current list of what's still open.

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
diagnosed this way (see this file's "Fourth round"/"Fifth round" sections below for the
worked examples — wrong accessors, an overly-strict assertion, and one genuine
site-crashing plugin landmine).

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

## 2026-07-16, fourth round: six new Crocoblock plugins (Jet Appointments Booking,
JetBooking, JetElements, JetMenu, JetReviews, JetWooBuilder)

Follow-up to the third round's gist audit, which flagged JetBooking/JetAppointments as
the highest-priority next candidate. Source for all six plugins was checked out to
`plugins/<slug>/` (gitignored, not committed, same pattern as the original three) and
this repo's full draft→verify→ship discipline was applied to each: read `docs/principles.md`/
`docs/authoring-guide.md`, mine the source, write `SKILL.md`+`TEST-REGIMEN.md`+`tests.php`
together, deploy and fix what the suite finds.

**Correcting the gist backlog first.** `.claude/skills/_other-plugins-backlog/OTHER-PLUGINS.md`
had a single combined "JetBooking / JetAppointments" section (~25 gists) written before it
was confirmed these are two separate plugins. Every gist was re-fetched and read for real
(not re-guessed from its one-line description) to check whether it calls `jet_apb()`/
`JET_APB` (→ Jet Appointments Booking) or `jet_abaf()`/`JET_ABAF` (→ JetBooking), or —
where a gist referenced neither directly — by grepping the exact hook/meta-key string
against both local plugin trees. Result: **12 gists to Jet Appointments Booking, 13 to
JetBooking, zero left genuinely ambiguous**, each with a `[confirmed via: ...]`
traceability tag.

**11 new skills landed**, one per plugin except JetElements/JetMenu/JetReviews/JetBooking
which each split into 2 given distinct capability areas (same "split by capability, not by
plugin name" principle as JetEngine's own cct-internals/relations/modules/query-builder/
listings-macros split):

- `jetappointments-core` / `jetappointments-integrations` — Jet Appointments Booking 2.5.1:
  the `jet_apb()` singleton and custom-table data model, calendar/time-slots hooks, the
  form-action insert pipeline and public confirm/cancel pages; the JetFormBuilder action
  class + `jet-apb/jet-fb/action/success` event and WooCommerce integration.
- `jetelements-widgets` / `jetelements-query-gateway` — JetElements 2.9.1.2: widget/addon
  registration, per-widget include/exclude-controls filters, the carousel-options pattern,
  a documented "don't call `register_addon()` directly" landmine; and the cross-plugin
  Query Gateway integration with JetEngine's Query Builder (`jet-elements/widget/loop-items`
  + the `jet-engine-query-gateway/*` hook quartet).
- `jetmenu-structure` / `jetmenu-extensibility` — JetMenu 3.0.2.1: the Mega Menu Items CPT
  and its three separate (easily-confused) storage locations, the NextGen-vs-legacy render
  pipeline, two nav-menu walkers, `jet-menu-api/v2` REST; and the real extension surface
  (Dynamic Visibility custom conditions, walker-level markup filters, the JS event trio).
- `jetreviews-data-model` / `jetreviews-conditions` — JetReviews 3.1.0.1: six custom DB
  tables (not a CPT/CCT), the Sources abstraction, Review Types, structured-data types,
  and a REST permission-callback gap (a Condition only gates the widget UI, not a direct
  REST POST); plus the Conditions/Verifications registries — including a **genuine plugin
  bug found and documented**: every built-in condition's invalid-message filter is called
  with a single-quoted string containing a literal, un-interpolated `{$this->slug}`, so
  the "obvious" per-slug hook name a developer would guess for it never actually fires.
- `jetwoobuilder-templates` — JetWooBuilder 2.3.3: the `%macro%` engine (with its
  `[a-z_-]+`-only regex gotcha), the `jet-woo-builder/template-functions/*` widget-markup
  filter family, the Elementor Document/template system, and the "two separate settings
  stores" gotcha (`jet_woo_builder_settings()` vs `jet_woo_builder_shop_settings()`).
- `jetbooking-calendar` / `jetbooking-integrations` — JetBooking 4.1.2.1: the
  `\JET_ABAF\Plugin` singleton, booking DB tables, the real shape of `jet_abaf_price`
  (one serialized meta key with sub-keys, not several flat ones), and the form-insert
  pipeline; plus WooCommerce cart/order hooks (confirming the singular `jet-appointment/`
  vs plural `jet-booking/` naming collision with the *other* booking plugin), Google
  Calendar export, and the front-end `window.JetPlugins.hooks` JS API verified directly
  against the shipped JS bundle.

**Sandbox activation and live-testing.** Before this round, none of the six plugins were
active on `jackfruit.epeak.studio`. The site owner activated all six mid-session; a
recheck via `resource-get-website-config` showed **three actually came up** (Jet
Appointments Booking, JetElements, JetWooBuilder — alongside Elementor/Elementor Pro,
already active) while **JetBooking, JetMenu, and JetReviews did not** activate this round
— their `SKILL.md`/`TEST-REGIMEN.md`/`tests.php` are written and ready (same
"as-if-ready" discipline as the original JetSmartFilters round) but genuinely blocked on
installation, flagged explicitly at the top of each affected `TEST-REGIMEN.md`.

All 5 suites for the 3 now-active plugins were deployed and run (ids 35-39, see
`docs/code-snippets-rest-api.md`) — **27/27 assertions passing**, 2 issues caught and
fixed, both **test bugs, not plugin/doc bugs** (worked triage examples for a future
session, per `docs/test-harness-guide.md`'s "Reading results" procedure):
- `jetappointments-core`'s `apb-4` used a single-line `strpos()` against a call site whose
  real args wrap across multiple lines (`render_action_result_page(\n\t\t\t'error', ...)`)
  — fixed with a whitespace-tolerant regex.
- `jetelements-query-gateway`'s `jeg-1` first fataled (passed `$widget = null` into a hook
  a real, already-registered JetEngine listener consumes and unconditionally calls
  `->get_name()` on), then — after fixing that with a fake widget stub — broke `jeg-2`
  instead, because `jeg-1`'s own cleanup used `remove_all_filters()`, which also stripped
  JetEngine's real listener off that shared hook. Fixed by switching to `remove_filter()`
  with the exact callback reference. A clean illustration that a live suite runs inside a
  real, already-bootstrapped site — global mutations (removing *all* filters on a hook)
  can quietly corrupt a *different* assertion in the same run, not just the one that made
  the mutation.

Also surfaced and documented (not part of `tests.php`, since Windows PowerShell isn't
this repo's usual deploy path): two `Invoke-RestMethod`/`ConvertTo-Json` gotchas specific
to deploying Code Snippets from PowerShell rather than `curl` — see
`docs/code-snippets-rest-api.md`'s new "PowerShell + Invoke-RestMethod gotchas" section.

**What's still open from this round**: `jetbooking-calendar`, `jetbooking-integrations`,
`jetmenu-structure`, `jetmenu-extensibility`, `jetreviews-data-model`, `jetreviews-conditions`
need their plugins actually installed on the sandbox before their `tests.php` suites can
be deployed and run — same documented-blocker pattern as everything else in this repo
that's source-verified but not yet sandbox-confirmed. `jetwoobuilder-templates`' WC-dependent
edge cases (a real product driving `jwb-5`, `jwb-8`'s template swap actually serving a WC
page) also remain open since WooCommerce itself isn't installed on this sandbox.

## 2026-07-16, fifth round: five more Crocoblock plugins (JetBlog, JetCompareWishlist,
JetPopup, JetTabs, JetThemeCore) + unblocking the fourth round's stragglers

The site owner added real source for five more plugins (`plugins/jet-blog/`,
`plugins/jet-compare-wishlist/`, `plugins/jet-popup/`, `plugins/jet-tabs/`,
`plugins/jet-theme-core/`) and activated **every** plugin on the sandbox, including
WooCommerce — this also unblocked `jetbooking-calendar`/`jetbooking-integrations`,
`jetmenu-structure`/`jetmenu-extensibility`, and `jetreviews-data-model`/
`jetreviews-conditions`, which were source-verified-only from the fourth round.
JetSearch is active on the sandbox but has no local source checked out — it remains the
one plugin left in `.claude/skills/_other-plugins-backlog/OTHER-PLUGINS.md`.

**12 new skills**, each with `SKILL.md`/`TEST-REGIMEN.md`/`tests.php`, all deployed and
green: `jetblog-query-pipeline` (5/5), `jetblog-widgets-extensibility` (6/6),
`jetcomparewishlist-data-store` (6/6), `jetcomparewishlist-integrations` (5/5),
`jetpopup-conditions` (6/6), `jetpopup-extensibility` (7/7), `jetpopup-render-triggers`
(5/5), `jettabs-query-gateway` (4/4), `jettabs-widgets` (4/4), `jetthemecore-locations`
(4/4), `jetthemecore-template-conditions` (5/5), `jetthemecore-theme-builder` (4/4).

**The six previously-blocked skills are now live-verified too**: `jetbooking-calendar`
(7/7), `jetbooking-integrations` (6/6), `jetmenu-structure` (10/10), `jetmenu-extensibility`
(7/7), `jetreviews-data-model` (6/6), `jetreviews-conditions` (5/5). Total across this
round: **101/102 assertions passing on first confirmation, 102/102 after the last fix**.

**Two real plugin/doc bugs found and fixed** (not test bugs):
- `jetmenu-structure`: SKILL.md had claimed re-instantiating `\Jet_Menu\Options_Manager`
  "just" desyncs a second object, like most other manager classes. Live-testing found
  it's actually a **fatal** — its constructor's `init_options()` does an unconditional
  `require` (not `require_once`) per options-module file, so a second call throws
  "Cannot redeclare class". Corrected the doc, moved it into the same fatal-risk group as
  `Render\Manager`/`Blocks\Manager`.
- `jetthemecore-template-conditions`: similarly, `register_cpt_conditions()` does an
  unconditional `require` of 4 condition class files every call — calling it a second
  time (which the original test did) fatals the same way. Corrected the doc and rewrote
  the test to check an already-real, boot-time-generated CPT condition (WooCommerce's
  `product`) instead of re-triggering registration.
- `jetthemecore-locations`: a real, environment-dependent doc gap, not exactly a "bug" —
  with WooCommerce active, JetThemeCore registers 6 additional WooCommerce-specific
  structures/locations beyond the "6 core structures / 4 core locations" this skill had
  documented as the complete set. Corrected to "at least" language.

**Several test-only bugs and Elementor-version quirks**, all resolved (see each affected
skill's TEST-REGIMEN.md for the full story): a strict `===` check against a truthy
non-bool return value; an Elementor lazy-widget-registration race causing a "Cannot
redeclare class" fatal when a test's own guarded `require` ran before Elementor's own
first-time widget-type load; repeated Elementor `Widget_Base` constructor-argument
validation issues on `_get_posts()`-dependent tests, eventually resolved by testing the
underlying filter mechanisms directly rather than through a fully-live widget instance
(same "direct hook test" pattern as `jetelements-query-gateway`); a CPT's capabilities
being read before the test's own filter took effect; wrong response-key assumptions
about `create_page_template()`'s return shape; a fabricated post-type slug silently
exceeding WordPress's 20-character limit; and one genuinely flaky assertion
(`jetthemecore-locations`' `jtl-4`) that had hardcoded a dynamic filter name, fixed to
compute the real content-type the same way the plugin's own `do_location()` does.

**New PowerShell-with-Elementor lesson**: instantiating a real Elementor widget via `new
SomeWidget()` and calling a method that resolves the widget's own settings (like
`_get_posts()`) is far more fragile across Elementor versions than instantiating a widget
just to call a Reflection-exposed helper method — prefer testing the underlying
`apply_filters()`/`do_action()` calls directly when the actual claim under test doesn't
need a fully-initialized widget object.

## 2026-07-17, seventh round: closing the last within-plugin gaps (JetEngine Booking Forms,
JetFormBuilder Payment Gateways, JetEngine REST API) + a real Data Stores fatal

The site owner added source for JetFormBuilder's `modules/gateways/` (previously absent)
and JetEngine gained working Calendar/Forms/REST-API-Listings source paths that were
flagged but never picked up in earlier rounds. Activated the `dynamic-visibility`,
`data-stores`, `calendar`, `booking-forms`, and `rest-api-listings` JetEngine modules on
the sandbox via `tool-manage-modules`, then built out the three remaining gaps from the
old audit backlog (now `docs/known-gaps.md`):

- **`jetengine-booking-forms`** (new, snippet id 75) — JetEngine's own built-in Dynamic
  Calendar module and legacy "Forms (Legacy)" builder, both distinct from JetFormBuilder.
  **12/12 pass, clean first run**, no plugin or test bugs. Two documentation additions:
  `get_calendar_group_keys()`/`get_notification_types()` both return more entries live
  than the documented core set, since other active plugins on this multi-plugin sandbox
  append to the same filterable lists — corrected to "floor, not ceiling" language.
- **`jetformbuilder-payment-gateways`** (new, snippet id 76) — extending
  `Base_Gateway`/`Base_Scenario_Gateway`, the DB-backed `Payment_Model` data model, the
  `GATEWAY.SUCCESS`/`GATEWAY.FAILED` Action Events, and the PayPal reference
  implementation (the only gateway registered on this sandbox, no live credentials
  needed for any assertion). **9/9 pass, clean first run.**
- **`jetengine-rest-api`** (new, snippet id 77) — JetEngine's REST surface in both
  directions: exposing its own CPT/CCT/meta-box/options-page/query-builder data over
  REST (including CCTs' own `/jet-cct/{slug}` controller, since they aren't real WP
  posts), and consuming a third-party REST API as a Listing Grid source via the Rest API
  Listings module. Tests reused existing fixtures (CCT id 15, query id 16, relation id
  17) rather than creating new state, and drove REST routes in-process via
  `rest_do_request()` rather than a real HTTP round trip (per the `jetblog-query-pipeline`
  lesson). First run 9/11 — two test-only bugs, both the same "class only lazily
  `require`d behind a gate" shape as the `jetengine-modules` Data Stores fix above:
  Relations' `Public_Controller` (only loaded from inside `Relation::init_public_rest_api()`,
  itself gated by the relation's own `rest_get_enabled`/`rest_post_enabled` args), and
  `Query_Endpoint`'s route never registering because its `rest_api_init` hook had already
  fired for the outer request the suite runs inside of. **11/11 pass after both fixes.**
  Also confirmed a transient, unrelated infrastructure hiccup: the sandbox's edge/WAF
  briefly served a JS bot-challenge page instead of JSON for every route mid-session,
  resolving itself after a short wait — not caused by, or fixable from, this repo's side.

**One real plugin gotcha found and fixed while re-running `jetengine-modules` with Data
Stores now genuinely active** (previously it was only gating-tested): `mod-8` fatal'd
with `Class ...\Stores\Factory not found`. Root cause: `Stores\Manager::register_stores()`
only `require`s `stores/factory.php` inside its `if ( ! empty( $stores ) )` branch — on a
site with zero stores configured (true here, right after activation), that `require`
never runs, so a direct `register_store()` call fatals unless the caller force-loads
`factory.php` first. Documented as a new gotcha in `jetengine-modules/SKILL.md`; fixed
the test to force-load it. **10/10 pass after the fix**, and `mod-4`/`mod-5`/`mod-8` now
exercise real Dynamic Visibility/Data Stores behavior instead of just gating-consistency.

Also this round: removed the stale `docs/audit-2026-07-16.md` journal (fully superseded
by this file) and replaced it with a short, current `docs/known-gaps.md`; folded its two
still-open threads (JetEngine's possible meta-field read/write helper, Dynamic Functions
vs. `%macro%`) plus two newly-surfaced ones (JetFormBuilder's separate macro-filter
system, JetEngine Profile Builder) into that file.

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
  deactivate it**, everything else (ids 23, 24, 27-45, 52-63, 75-77 as of this writing)
  are per-skill suites that depend on it. Id 25 is a deactivated crash-reproduction
  diagnostic — documented on purpose, do not activate it or hit its route. A number of
  ids in the 46-74 range were one-off `ZZZ-DIAG` isolation probes used during earlier
  rounds' triage, all deactivated after use (see `docs/code-snippets-rest-api.md`).
- **All five optional JetEngine modules this repo's skills document are now active**
  (as of the seventh round): Dynamic Visibility, Data Stores, Calendar, Forms (Legacy),
  and Rest API Listings — activated via `tool-manage-modules` so `jetengine-modules`,
  `jetengine-booking-forms`, and `jetengine-rest-api` all exercise real behavior instead
  of module-gating-only checks.
- **Every plugin this repo covers is now active on the sandbox** (as of the fifth
  round): JetEngine, JetFormBuilder, JetSmartFilters, Jet Appointments Booking,
  JetBooking, JetElements, JetMenu, JetReviews, JetWooBuilder, JetBlog,
  JetCompareWishlist, JetPopup, JetTabs, JetThemeCore — plus Elementor/Elementor Pro (a
  hard dependency for the Elementor-based plugins), WooCommerce (newly installed this
  round, unblocking several WC-dependent test paths), JetBlocks (activated but out of
  scope for this repo — no skill covers it), and JetSearch (active, but no local source
  checked out — see `OTHER-PLUGINS.md`).

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
`docs/known-gaps.md`.

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

**Deliberately not attempted this round** (scoped out for time, not forgotten): three
entirely new, sizeable JetEngine/JFB subsystems the audit surfaced with no owning skill
yet — **Update, 2026-07-17: two of these three are now done.**
- ~~**JetFormBuilder Payment Gateways module**~~ — done, see `jetformbuilder-payment-gateways`
  (below, "seventh round").
- **JetFormBuilder's own macro-filter system** (`%field|filter(args)%`,
  `jet-form-builder/content-filters`) — a separate implementation from JetEngine's
  `%macro%` engine with confusingly similar syntax; ~12 built-in filters, zero coverage.
  Still open — see `docs/known-gaps.md`.
- ~~**JetEngine Profile Builder module** and **REST API Listings module**~~ — REST API
  Listings is now done, see `jetengine-rest-api` (below, "seventh round"). Profile
  Builder is still open — see `docs/known-gaps.md`.
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

## Adding a new Crocoblock plugin (JetSearch, JetStyleManager, etc.)

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
(`jetsmartfilters-query`'s `Storage\Controller` — see this file's "Fourth round" section
above) from directly instantiating a class whose singleton wrapper already
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
