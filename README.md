# jetengine-skills

A small, growing collection of [Claude Code Skills](https://docs.claude.com/en/docs/claude-code/skills) for working
with the [JetEngine](https://jetengine.crocoblock.com/) WordPress plugin — writing PHP snippets, hooking into
JetEngine forms, querying Custom Content Types (CCTs), resolving relations, and similar day-to-day tasks.

## Why this exists

We couldn't find any existing skills geared toward JetEngine development, so we started writing our own as we ran
into real problems (custom REST endpoints, CCT relations, form hooks, etc.). Each skill captures things we actually
verified against a running JetEngine site rather than pure guesswork from the docs.

This is **very much a work in progress**. Coverage is uneven and there are plenty of gaps. Contributions, fixes, and
new skills are welcome — feel free to open a PR.

## What's here

- [`jetengine-cct-internals`](.claude/skills/jetengine-cct-internals/SKILL.md) — reading Custom Content Type data
  directly from the database and using the `Jet_Engine\Relations\Manager` API to resolve relations between CCTs.

More to come.

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
