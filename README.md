# jetengine-skills

A growing collection of [Claude Code Skills](https://docs.claude.com/en/docs/claude-code/skills) for working with
the **Crocoblock** plugin ecosystem for WordPress — real, verified internals for writing PHP snippets, hooking into
forms, querying data, and resolving relations without hallucinating a plausible-sounding wrong function name.

Fifteen plugins are covered end to end, each with source-cited facts and a runnable, machine-checkable test suite
live-verified against a real WordPress sandbox: [JetEngine](https://jetengine.crocoblock.com/) (CCTs, relations,
listings, query builder, modules, calendar/booking, REST API), [JetFormBuilder](https://jetformbuilder.com/) (forms,
hooks, custom actions, fields, payment gateways), [JetSmartFilters](https://jetsmartfilters.com/) (filter → query
internals), [Jet Appointments Booking](https://crocoblock.com/plugins/jetappointment/) and
[JetBooking](https://crocoblock.com/plugins/jetbooking/) (two separate booking plugins, easy to conflate),
[JetElements](https://crocoblock.com/plugins/jetelements/) and [JetMenu](https://crocoblock.com/plugins/jetmenu/)
(Elementor widgets/mega-menu), [JetReviews](https://crocoblock.com/plugins/jetreviews/),
[JetWooBuilder](https://crocoblock.com/plugins/jetwoobuilder/), [JetBlog](https://crocoblock.com/plugins/jetblog/),
[JetCompareWishlist](https://crocoblock.com/plugins/jetcomparewishlist/),
[JetPopup](https://crocoblock.com/plugins/jetpopup/), [JetTabs](https://crocoblock.com/plugins/jettabs/), and
[JetThemeCore](https://crocoblock.com/plugins/jetthemecore/).

Repo layout is inspired by [WordPress/agent-skills](https://github.com/WordPress/agent-skills). Every `SKILL.md`
here conforms to the open [Agent Skills specification](https://agentskills.io/specification) (the format Anthropic
published and that Vercel's `skills.sh` ecosystem builds on) — plain frontmatter (`name`/`description`, both within
spec limits) plus Markdown body, no proprietary extensions, so these skills work in any spec-compliant agent, not
just Claude Code.

Picking this repo up mid-stream (human or agent)? Read [`HANDOFF.md`](HANDOFF.md) first — it's a running log of
sandbox/test-suite state, trust the most recent entry for "what's true right now." [`docs/known-gaps.md`](docs/known-gaps.md)
is a short, current list of what's still open.

## Why this exists

We couldn't find any existing skills geared toward JetEngine development, so we started writing our own as we ran
into real problems (custom REST endpoints, CCT relations, form hooks, etc.). Each skill captures things we actually
verified against plugin source and/or a running JetEngine site rather than pure guesswork from the docs. See
[`docs/principles.md`](docs/principles.md) and [`docs/authoring-guide.md`](docs/authoring-guide.md) for how skills
here get written and verified.

This is **very much a work in progress**. Coverage is uneven and there are plenty of gaps — see
[`docs/known-gaps.md`](docs/known-gaps.md) for the honest list. Contributions, fixes, and new skills are welcome —
feel free to open a PR, or open an issue if a skill led you astray (plugins update; a claim that was true when
verified can go stale).

## What's here

Start with [`jetengine-router`](.claude/skills/jetengine-router/SKILL.md) — it classifies a task and points to the
right domain skill below.

### JetEngine

- [`jetengine-cct-internals`](.claude/skills/jetengine-cct-internals/SKILL.md) — reading Custom Content Type data
  directly from the database and using the `Jet_Engine\Relations\Manager` API to resolve relations between CCTs.
- [`jetengine-relations`](.claude/skills/jetengine-relations/SKILL.md) — relation/object types, where relation
  config is actually stored, bulk-fetching without N+1 queries, the "Connect Relation Items" JetFormBuilder action,
  and the public Relations REST API.
- [`jetengine-listings-macros`](.claude/skills/jetengine-listings-macros/SKILL.md) — `%macro%` token syntax and
  parsing, registering a custom macro (including subclassing a built-in one), and why a macro prints literally
  instead of resolving.
- [`jetengine-mcp-tools`](.claude/skills/jetengine-mcp-tools/SKILL.md) — JetEngine's built-in MCP Tools /
  Features API layer (`tool-add-cct`, `tool-add-cpt`, `tool-add-taxonomy`, `tool-add-meta-box`, `tool-add-query`,
  `tool-add-listing`, `tool-add-glossary`, `tool-manage-modules`) — what each tool actually creates under the
  hood, and why the `next_tool` hint field can't be trusted as a real tool name.
- [`jetengine-query-builder`](.claude/skills/jetengine-query-builder/SKILL.md) — fetching/running a configured
  Query Builder query (`Manager::get_query_by_id()`), the query-type factory, and registering a custom query type.
- [`jetengine-modules`](.claude/skills/jetengine-modules/SKILL.md) — Meta Boxes, Options Pages, Data Stores,
  Dynamic Visibility, Glossaries, and Custom Meta Tables — standalone JetEngine modules beyond CCT/Relations/Query
  Builder/Listings.
- [`jetengine-booking-forms`](.claude/skills/jetengine-booking-forms/SKILL.md) — JetEngine's own built-in Dynamic
  Calendar module and legacy "Forms (Legacy)" builder — both distinct from the separate JetFormBuilder plugin, and
  easily confused with it by name.
- [`jetengine-rest-api`](.claude/skills/jetengine-rest-api/SKILL.md) — JetEngine's REST surface in both directions:
  exposing its own CPT/CCT/meta-box/options-page/query-builder data over REST, and consuming a third-party REST API
  as a Listing Grid data source via the Rest API Listings module.

### JetFormBuilder

- [`jetformbuilder-hooks`](.claude/skills/jetformbuilder-hooks/SKILL.md) — the submission lifecycle (hook names,
  firing order, arg counts), the "Call Hook" action for running custom PHP without a full custom action class, and
  the `default-process-event/executors` filter that gates which actions actually run.
- [`jetformbuilder-actions`](.claude/skills/jetformbuilder-actions/SKILL.md) — writing a fully custom JetFormBuilder
  action class (base class, registration, reading field values, success/failure signaling) plus the Action
  Conditions system for gating whether a step runs at all.
- [`jetformbuilder-fields`](.claude/skills/jetformbuilder-fields/SKILL.md) — `jet_fb_context()`/`Parser_Context`
  for reading/writing submitted field values (including dotted-path repeater access), registering a custom field
  block type, field parsers, the validation-rule extension gap, presets, and where submitted entries are actually
  stored.
- [`jetformbuilder-payment-gateways`](.claude/skills/jetformbuilder-payment-gateways/SKILL.md) — extending
  `Base_Gateway`/`Base_Scenario_Gateway` for a custom payment gateway, the DB-backed `Payment_Model`/`Payer_Model`
  data model, the `GATEWAY.SUCCESS`/`GATEWAY.FAILED` Action Events, and the PayPal reference implementation.

### JetSmartFilters

- [`jetsmartfilters-query`](.claude/skills/jetsmartfilters-query/SKILL.md) — how JetSmartFilters turns a filter
  selection into a tax_query/meta_query, the AJAX filtering endpoint, registering custom filter types/providers, and
  worked `final-query` examples including a cross-plugin hook into JetEngine's Query Builder.

### Jet Appointments Booking

- [`jetappointments-core`](.claude/skills/jetappointments-core/SKILL.md) — Jet Appointments Booking's `jet_apb()`
  accessor, its custom-table data model, the calendar/time-slots customization hooks, the form-action
  appointment-insert pipeline, and the public confirm/cancel action-link pages.
- [`jetappointments-integrations`](.claude/skills/jetappointments-integrations/SKILL.md) — Jet Appointments
  Booking's "Insert appointment" JetFormBuilder action, its post-submission success event, and its WooCommerce
  integration.

### JetBooking

- [`jetbooking-calendar`](.claude/skills/jetbooking-calendar/SKILL.md) — JetBooking's `\JET_ABAF\Plugin` singleton,
  the date-range booking DB tables, seasonal/weekend pricing computation, and the form-submission booking-insert
  pipeline.
- [`jetbooking-integrations`](.claude/skills/jetbooking-integrations/SKILL.md) — JetBooking's WooCommerce cart/
  order integration, Google Calendar export, and the front-end `window.JetPlugins.hooks` JS API.

### JetElements

- [`jetelements-widgets`](.claude/skills/jetelements-widgets/SKILL.md) — registering/gating a JetElements For
  Elementor widget, per-widget include/exclude-controls filters, custom Elementor controls, REST endpoints, and
  the carousel-options extensibility pattern.
- [`jetelements-query-gateway`](.claude/skills/jetelements-query-gateway/SKILL.md) — the cross-plugin "Query
  Gateway" integration letting a JetElements widget's repeater "Items" control be driven by a JetEngine Query
  Builder query instead of static content.

### JetMenu

- [`jetmenu-structure`](.claude/skills/jetmenu-structure/SKILL.md) — JetMenu's Mega Menu Items CPT and meta
  structure, the rendering pipeline, the nav-menu walkers, and the `jet-menu-api/v2` REST endpoints.
- [`jetmenu-extensibility`](.claude/skills/jetmenu-extensibility/SKILL.md) — extending JetMenu: custom Dynamic
  Visibility conditions, walker-level markup filters, AJAX-lazy-load JS events, and compatibility-module
  registration.

### JetReviews

- [`jetreviews-data-model`](.claude/skills/jetreviews-data-model/SKILL.md) — JetReviews' custom DB tables, the
  Sources abstraction, the reviewer-avatar filter, Review Types, structured-data (rich snippet) types, and its
  REST API/Elementor widget registration.
- [`jetreviews-conditions`](.claude/skills/jetreviews-conditions/SKILL.md) — writing a custom "who can submit a
  review" condition or reviewer "badge" verification for JetReviews.

### JetWooBuilder

- [`jetwoobuilder-templates`](.claude/skills/jetwoobuilder-templates/SKILL.md) — JetWooBuilder's `%macro%` render
  engine, the `jet-woo-builder/template-functions/*` widget-markup filters, the Elementor Document/template
  system, and its two separately-keyed settings stores.

### JetBlog

- [`jetblog-query-pipeline`](.claude/skills/jetblog-query-pipeline/SKILL.md) — JetBlog's shared `jet-blog/pre-query`
  query-replacement hook, per-widget `*-query-args` filters, the optional JetEngine Query Builder integration, and
  the Smart Listing AJAX "load more" endpoint's HMAC settings-signature scheme.
- [`jetblog-widgets-extensibility`](.claude/skills/jetblog-widgets-extensibility/SKILL.md) — JetBlog's bootstrap,
  widget registration/enable-toggle mechanics, the `Jet_Blog_Base` control-visibility system, its own
  `jet-blog-api/v1` REST namespace, and the Video Playlist widget's YouTube/Vimeo video-data layer.

### JetCompareWishlist

- [`jetcomparewishlist-data-store`](.claude/skills/jetcomparewishlist-data-store/SKILL.md) — JetCompareWishlist's
  compare/wishlist data model (session/cookie/user-meta storage), the add/remove AJAX endpoints, and the
  widgets-store re-render mechanism.
- [`jetcomparewishlist-integrations`](.claude/skills/jetcomparewishlist-integrations/SKILL.md) — the
  `jet-cw/template-functions/*` filter family (the real JetEngine integration point), wiring compare/wishlist
  buttons into JetWooBuilder/WooCommerce templates, and its compatibility packages.

### JetPopup

- [`jetpopup-conditions`](.claude/skills/jetpopup-conditions/SKILL.md) — JetPopup's display-condition system,
  registering a custom condition type, and the AND/OR relation-matching algorithm (including the separate
  "exclude" filters for each relation type).
- [`jetpopup-extensibility`](.claude/skills/jetpopup-extensibility/SKILL.md) — JetPopup's `jet-popup/access-cap`
  capability gate (which also drives REST `permission_callback`), its `jet-popup/v2` REST namespace, and the
  cross-block Data Attributes/compatibility-module systems.
- [`jetpopup-render-triggers`](.claude/skills/jetpopup-render-triggers/SKILL.md) — the `wp_footer` render pipeline
  that decides which popups are "defined" for a request, the `jet-popup-open-trigger`/`jet-popup-close-trigger`
  jQuery event API, and the AJAX lazy-content endpoint.

### JetTabs

- [`jettabs-query-gateway`](.claude/skills/jettabs-query-gateway/SKILL.md) — JetTabs' own independent copy of the
  cross-plugin "Query Gateway" integration with JetEngine (a real gap exists in Image Accordion's copy of it — see
  the skill for details).
- [`jettabs-widgets`](.claude/skills/jettabs-widgets/SKILL.md) — registering/customizing a JetTabs widget (Tabs,
  Accordion, Image Accordion, Switcher), the `jet-tabs/widgets/template_id`/`template_content` ajax-template
  filters, and the `jet-query` custom Elementor control.

### JetThemeCore

- [`jetthemecore-template-conditions`](.claude/skills/jetthemecore-template-conditions/SKILL.md) — JetThemeCore's
  Template Conditions registry, registering a custom condition type, and how CPT conditions get auto-generated.
- [`jetthemecore-locations`](.claude/skills/jetthemecore-locations/SKILL.md) — the Structures/Locations render
  pipeline that turns a matched condition into actual rendered Header/Footer/Single/Archive output.
- [`jetthemecore-theme-builder`](.claude/skills/jetthemecore-theme-builder/SKILL.md) — JetThemeCore's Theme
  Builder "Page Template" layout system, its own AND/OR relation type, and the priority-averaging tie-break
  between competing Page Templates.

---

Every skill above also has a `TEST-REGIMEN.md` next to its `SKILL.md` — a runnable validation checklist for a future
session with sandbox (WP snippet read/write + log-viewing endpoint) access to confirm the claims against real
runtime behavior, not just source reading. See [`docs/test-regimen-guide.md`](docs/test-regimen-guide.md).

Every domain skill also has a `tests.php` — a machine-checkable suite (deployed as a Code Snippets snippet) that
returns structured pass/fail JSON over REST, so a future agent can re-verify every claim with one `curl` instead of
reinventing probe code. See [`docs/test-harness-guide.md`](docs/test-harness-guide.md) and
[`test-harness/core-snippet.php`](test-harness/core-snippet.php) for the shared infrastructure this depends on.

Working on a *different* Crocoblock plugin (JetSearch is the only one left without a skill here)?
[`.claude/skills/_other-plugins-backlog/OTHER-PLUGINS.md`](.claude/skills/_other-plugins-backlog/OTHER-PLUGINS.md)
has gist-sourced hook/class leads for it, gathered as a byproduct of auditing this repo's own fifteen plugins —
not a skill itself, just a head start for whoever builds it out.

## Sources

Every claim in this repo traces back to one of:

- **Real plugin source**, checked out and read directly (not distributed docs or marketing copy) — see
  [`docs/principles.md`](docs/principles.md) for why that matters.
- **A live WordPress sandbox**, verified via a small runnable test-harness deployed through the
  [Code Snippets](https://wordpress.org/plugins/code-snippets/) REST API — see
  [`docs/code-snippets-rest-api.md`](docs/code-snippets-rest-api.md) and
  [`docs/test-harness-guide.md`](docs/test-harness-guide.md).
- **Crocoblock's own developer documentation**, [github.com/Crocoblock/developer-documentation](https://github.com/Crocoblock/developer-documentation)
  — cross-checked against source, not trusted blindly (a couple of dev-docs examples turned out to be slightly
  stale or incomplete versus the running plugin; see `HANDOFF.md`'s "second round" for specifics).
- **Crocoblock's public GitHub Gists**, [gist.github.com/Crocoblock](https://gist.github.com/Crocoblock) — used as
  leads for real-world hook usage, always verified (or corrected) against actual plugin source before becoming a
  skill claim, never copied as-is.
- The plugins' own product pages, linked throughout this README, for feature-level context.

## How to install these skills

Claude Code Skills live in a `.claude/skills/` folder and are picked up automatically — there's no package manager
or install step.

**Option 1 — clone into your project**

```bash
git clone https://github.com/EPEAKSTUDIO/wp-jetengine-skills.git
cp -r wp-jetengine-skills/.claude/skills/* /path/to/your-project/.claude/skills/
```

**Option 2 — copy a single skill**

Each skill is just a folder containing a `SKILL.md` (and optionally supporting files). Copy the folder you want
straight into your own project's `.claude/skills/` directory, e.g.:

```bash
mkdir -p /path/to/your-project/.claude/skills
cp -r .claude/skills/jetengine-cct-internals /path/to/your-project/.claude/skills/
```

**Option 3 — global install (available in every project)**

Copy the skill folder(s) into `~/.claude/skills/` instead of a project-local `.claude/skills/`.

Once a skill's folder is in place, Claude Code will list it as available and use it automatically when it's
relevant — no restart or config needed beyond having the files there.

We're not sure this is the best way to distribute/share skills yet — suggestions welcome.

## Contributing

Got a JetEngine trick, hook, or gotcha that took you a while to figure out? Turn it into a `SKILL.md` (see an
existing skill for the expected format) and open a PR. Real, verified behavior is preferred over speculation — if
you can point to source or reproduce it live, even better. Found something in here that's gone stale after a
plugin update? Open an issue or a PR with the fix; that's exactly the kind of drift this repo expects and welcomes
help catching.
