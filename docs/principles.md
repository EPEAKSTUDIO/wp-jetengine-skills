# Principles

- Prefer small, composable skills over one mega-skill per plugin. Split by capability
  (CCT internals, relations, form hooks, filter queries), not by plugin name alone.
- Every fact in a `SKILL.md` must be **verified** — against real plugin source and/or a
  running sandbox site — not inferred from marketing docs or guessed from method names.
  If something is unverified, say so explicitly rather than presenting it as fact.
- Keep `SKILL.md` short and procedural; push long explanations, code dumps, and edge
  cases into `references/*.md` one hop away.
- Prefer showing the exact debugging technique that found a fact (e.g. dumping
  `get_class_methods()`) over just asserting the conclusion — it lets the next person
  re-verify or extend it when the plugin updates.
- Note plugin version numbers when behavior is version-sensitive. Crocoblock ships
  frequent releases and internals do change between major versions.
- Route first: `jetengine-router` should point to the right domain skill before anyone
  starts writing PHP against JetEngine/JetFormBuilder/JetSmartFilters internals.
