# jetengine-skills

A growing collection of [Claude Code Skills](https://docs.claude.com/en/docs/claude-code/skills) for working with
the Crocoblock JetEngine ecosystem — [JetEngine](https://jetengine.crocoblock.com/) (CCTs, relations, listings),
[JetFormBuilder](https://jetformbuilder.com/) (forms, hooks, custom actions), and
[JetSmartFilters](https://jetsmartfilters.com/) (filter → query internals) — for the day-to-day tasks of writing PHP
snippets, hooking into forms, querying data, and resolving relations.

Repo layout is inspired by [WordPress/agent-skills](https://github.com/WordPress/agent-skills). Every `SKILL.md`
here conforms to the open [Agent Skills specification](https://agentskills.io/specification) (the format Anthropic
published and that Vercel's `skills.sh` ecosystem builds on) — plain frontmatter (`name`/`description`, both within
spec limits) plus Markdown body, no proprietary extensions, so these skills work in any spec-compliant agent, not
just Claude Code.

Picking this repo up mid-stream (human or agent)? Read [`HANDOFF.md`](HANDOFF.md) first
— it has current sandbox/test-suite state and the up-to-date backlog;
[`docs/audit-2026-07-16.md`](docs/audit-2026-07-16.md) is a historical log of how
coverage grew, not a live status board.

## Why this exists

We couldn't find any existing skills geared toward JetEngine development, so we started writing our own as we ran
into real problems (custom REST endpoints, CCT relations, form hooks, etc.). Each skill captures things we actually
verified against plugin source and/or a running JetEngine site rather than pure guesswork from the docs. See
[`docs/principles.md`](docs/principles.md) and [`docs/authoring-guide.md`](docs/authoring-guide.md) for how skills
here get written and verified.

This is **very much a work in progress**. Coverage is uneven and there are plenty of gaps. Contributions, fixes, and
new skills are welcome — feel free to open a PR.

## What's here

- [`jetengine-router`](.claude/skills/jetengine-router/SKILL.md) — start here: classifies a task and points to the
  right domain skill below.
- [`jetengine-cct-internals`](.claude/skills/jetengine-cct-internals/SKILL.md) — reading Custom Content Type data
  directly from the database and using the `Jet_Engine\Relations\Manager` API to resolve relations between CCTs.
- [`jetengine-relations`](.claude/skills/jetengine-relations/SKILL.md) — relation/object types, where relation
  config is actually stored, bulk-fetching without N+1 queries, the "Connect Relation Items" JetFormBuilder action,
  and the public Relations REST API.
- [`jetformbuilder-hooks`](.claude/skills/jetformbuilder-hooks/SKILL.md) — the submission lifecycle (hook names,
  firing order, arg counts) and the "Call Hook" action for running custom PHP without a full custom action class.
- [`jetformbuilder-actions`](.claude/skills/jetformbuilder-actions/SKILL.md) — writing a fully custom JetFormBuilder
  action class: base class, registration, reading field values, success/failure signaling.
- [`jetsmartfilters-query`](.claude/skills/jetsmartfilters-query/SKILL.md) — how JetSmartFilters turns a filter
  selection into a tax_query/meta_query, the AJAX filtering endpoint, and registering custom filter types/providers.
- [`jetengine-listings-macros`](.claude/skills/jetengine-listings-macros/SKILL.md) — `%macro%` token syntax and
  parsing, registering a custom macro, and why a macro prints literally instead of resolving.
- [`jetengine-mcp-tools`](.claude/skills/jetengine-mcp-tools/SKILL.md) — JetEngine's built-in MCP Tools /
  Features API layer (`tool-add-cct`, `tool-add-cpt`, `tool-add-taxonomy`, `tool-add-meta-box`, `tool-add-query`,
  `tool-add-listing`, `tool-add-glossary`, `tool-manage-modules`) — what each tool actually creates under the
  hood, and why the `next_tool` hint field can't be trusted as a real tool name.
- [`jetengine-query-builder`](.claude/skills/jetengine-query-builder/SKILL.md) — fetching/running a configured
  Query Builder query (`Manager::get_query_by_id()`), the query-type factory, and registering a custom query type.
- [`jetengine-modules`](.claude/skills/jetengine-modules/SKILL.md) — Meta Boxes, Options Pages, Data Stores,
  Dynamic Visibility, Glossaries, and Custom Meta Tables — standalone JetEngine modules beyond CCT/Relations/Query
  Builder/Listings.
- [`jetformbuilder-fields`](.claude/skills/jetformbuilder-fields/SKILL.md) — `jet_fb_context()`/`Parser_Context`
  for reading/writing submitted field values (including dotted-path repeater access), registering a custom field
  block type, field parsers, the validation-rule extension gap, presets, and where submitted entries are actually
  stored.

Every skill above also has a `TEST-REGIMEN.md` next to its `SKILL.md` — a runnable validation checklist for a future
session with sandbox (WP snippet read/write + log-viewing endpoint) access to confirm the claims against real
runtime behavior, not just source reading. See [`docs/test-regimen-guide.md`](docs/test-regimen-guide.md).

Some skills additionally have a `tests.php` — a machine-checkable suite (deployed as a Code Snippets snippet) that
returns structured pass/fail JSON over REST, so a future agent can re-verify every claim with one `curl` instead of
reinventing probe code. See [`docs/test-harness-guide.md`](docs/test-harness-guide.md) and
[`test-harness/core-snippet.php`](test-harness/core-snippet.php) for the shared infrastructure this depends on.

More to come — see [`docs/audit-2026-07-16.md`](docs/audit-2026-07-16.md) for a full gap analysis against the
plugin source (what's covered, what isn't yet, prioritized backlog) and the Open Skills spec compliance check.

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

Got a JetEngine trick, hook, or gotcha that took you a while to figure out? Turn it into a `SKILL.md` (see the
existing skill for the expected format) and open a PR. Real, verified behavior is preferred over speculation.
