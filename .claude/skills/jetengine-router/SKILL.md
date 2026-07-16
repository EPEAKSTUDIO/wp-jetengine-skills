---
name: jetengine-router
description: Use first when a task touches JetEngine, JetFormBuilder, or JetSmartFilters and it's unclear which domain skill applies — e.g. "why is this field empty", "how do I hook into form submission", "how do I query this filter". Classifies the task and points to the right skill instead of guessing.
license: MIT
metadata:
  author: project
  version: "0.1.0"
---

# JetEngine/JetFormBuilder/JetSmartFilters Router

Route the task to the right skill before writing any PHP. This repo is split by
capability, not just by plugin name, because a single task (e.g. "a form submission
should create a related CCT record") often spans more than one plugin.

## Decision tree

- **Reading/writing Custom Content Type (CCT) data directly from the DB** →
  `jetengine-cct-internals`
- **Resolving parent/child links between CCTs (or CCTs and CPTs)** →
  `jetengine-relations`
- **Hooking into JetFormBuilder form submission, validation, or lifecycle actions**
  (`jet-form-builder/form-handler/before-send`, custom actions, macros in a form) →
  `jetformbuilder-hooks`
- **Building a custom JetFormBuilder action/handler** (a PHP class that runs on submit) →
  `jetformbuilder-actions`
- **Building/debugging JetSmartFilters queries** (filter → query args, AJAX filtering,
  custom filter types) → `jetsmartfilters-query`
- **Listing grid / listing item dynamic field macros** (`%macro%` resolution, custom
  macros) → `jetengine-listings-macros`
- **Deciding whether to call a JetEngine MCP tool** (`tool-add-cct`, `tool-add-cpt`,
  `tool-add-taxonomy`, `tool-add-meta-box`, `tool-add-query`, `tool-add-listing`,
  `tool-add-glossary`, `tool-manage-modules`) **vs. writing raw PHP**, or debugging why a
  tool-created entity doesn't look as expected → `jetengine-mcp-tools`
- **Fetching/running a configured Query Builder query, or registering a custom query
  type** (SQL/Posts/Terms/Users/Comments/Repeater queries) → `jetengine-query-builder`
- **Meta Boxes (custom fields on a post/term/user/options page — not CCT), Options
  Pages, Data Stores (favorites/recently-viewed), Dynamic Visibility (conditional
  display), Glossaries, or Custom Meta Tables (custom-table post meta storage)** →
  `jetengine-modules`
- **Reading/writing a JetFormBuilder submitted field value generically (incl. nested
  repeater fields by dotted path), registering a custom field block type, adding
  validation, wiring a preset/dynamic default, or reading previously-submitted form
  entries from storage** → `jetformbuilder-fields`
- **None of the above / genuinely new territory** → mine the plugin source first (see
  `docs/authoring-guide.md`), then decide whether it fits an existing skill or needs a
  new one.

## Inputs to gather before routing

- Which plugin(s) are involved (JetEngine core, JetFormBuilder, JetSmartFilters,
  JetThemeCore, etc.) — check `wp-content/plugins/` for exact plugin slugs/versions.
- Whether this is CCT-based, CPT-based, or a mix.
- Whether the fix needs to run in a hook/filter vs. a one-off script/REST endpoint.

## Escalation

If the task doesn't fit any existing skill, treat it as a candidate for a new skill —
follow `docs/authoring-guide.md` rather than improvising ungrounded advice.
