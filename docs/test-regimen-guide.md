# Test regimen guide

Every skill in this repo gets a `TEST-REGIMEN.md` next to its `SKILL.md`. It's a
runnable validation checklist for a **future agent session that has WP snippet
read/write access and can hit a REST endpoint to view logs** on the sandbox site (the
one with JetEngine, JetFormBuilder, and JetSmartFilters installed). Its job is to turn
every "verified fact" in the skill from "verified by reading source" into "verified by
observing real runtime behavior" — source-reading can misjudge intent; only running the
code confirms it.

## Why this exists

Skills in this repo are written by mining plugin source (grep + read), which proves
"the code does X" but not always "X happens the way I think when it actually runs" —
hook firing order under real WP load, arg values at runtime, and edge cases (empty
input, missing relation, disabled indexer) are easiest to get subtly wrong from static
reading alone. A test regimen closes that loop.

## Format

```markdown
# Test regimen: <skill-name>

Validates claims in `SKILL.md`. Run against the sandbox site. For each test: set up the
snippet/state described, trigger the action, then check the expected observable via the
logging endpoint. Mark PASS/FAIL/UNCLEAR inline as you go — don't just delete failures,
record them (a FAIL against a documented claim means the skill needs a correction).

## Prerequisites

- A snippet plugin (e.g. WPCode/Code Snippets) with PHP execute permission.
- A logging sink reachable via REST/AJAX so results are visible without SSH/FTP —
  e.g. a small `error_log()`-to-file setup, or a custom `GET /wp-json/<ns>/debug-log`
  endpoint that dumps the last N lines of a dedicated debug log file. State which one
  you're using at the top of your run notes.

## Test 1: <short name>

**Claim being tested:** <quote or paraphrase the SKILL.md claim, with its section>

**Setup:**
- <snippet/config to add>

**Trigger:**
- <the action to perform — submit a specific form, hit a specific filter, etc.>

**Expected observable:**
- <exact log line / value / behavior expected, per the claim>

**Pass criteria:** <what distinguishes pass from fail>

## Test 2: ...
```

## Notes for whoever fills these in now (before the sandbox exists)

- Write tests **as if you already have sandbox access** — concrete snippet code,
  concrete hook names, concrete expected log lines — even though you can't run them
  yet. The next session's job is to execute, not to design.
- Order tests from cheapest/most-foundational to most involved (e.g. confirm a hook
  fires at all before testing its exact arg count).
- Every "gotcha" or surprising claim in a SKILL.md (arg counts, ordering, exception
  semantics) deserves its own test — those are exactly the things static reading can
  get wrong.
- If a claim can't be tested without data that doesn't exist yet on the sandbox (e.g. a
  real WooCommerce order), say what fixture/setup step creates it first.
