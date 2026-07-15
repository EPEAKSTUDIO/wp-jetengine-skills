# Authoring guide

How to add or extend a skill in this repo, adapted from
[WordPress/agent-skills](https://github.com/WordPress/agent-skills)'s approach.

## Golden rules

- Keep `SKILL.md` short and procedural; push depth into `references/`.
- Verify against real plugin source (see `/path/to/plugin` handed over for this repo)
  and/or a live sandbox site — never present docs-site guesswork as verified fact.
- Keep file references one hop from `SKILL.md` (avoid deep reference chains).
- Note the plugin version(s) a fact was confirmed against when behavior seems
  version-sensitive.

## SKILL.md frontmatter

```yaml
---
name: skill-name
description: "Use when <trigger>. Captures verified behavior of <what>, learned by <how>."
license: MIT
metadata:
  author: project
  version: "0.1.0"
---
```

## Suggested body sections

- **When to use** — concrete trigger scenarios (task shapes, error messages, symptoms).
- **Core facts** — the verified behavior itself, with code snippets.
- **Failure modes / gotchas** — things that look right but aren't (e.g. FK-looking
  columns that are actually unused).
- **How this was verified** — what was built/tested, against what data, so a future
  reader can judge how much to trust it and re-verify after plugin updates.

## Workflow: draft → verify → ship

1. **Route first** — check `jetengine-router/SKILL.md` to confirm which domain skill
   this belongs in (or whether it's a new one).
2. **Mine the source** — grep the plugin source for the relevant hooks/classes/tables
   before writing anything down.
3. **Verify against a live site** — use a temporary debug branch (dump
   `get_class_methods()`, log actual query results, etc.) rather than guessing method
   signatures from names.
4. **Write the skill** — short procedure + verified facts + how it was verified.
5. **Cross-check docs** — compare against the public JetEngine/JetFormBuilder/
   JetSmartFilters documentation for terminology and coverage gaps, but don't trust the
   docs over what you actually observed in source/runtime.

## Scaffolding a new skill

```
mkdir -p .claude/skills/<skill-name>/references
```

Then write `.claude/skills/<skill-name>/SKILL.md` following the frontmatter and section
shape above.
