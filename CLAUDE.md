# CLAUDE.md — JetEngine Skills repo

## What this repo is

A collection of verified Agent Skills (`skills/*/SKILL.md`) documenting real, non-obvious
internals of JetEngine and related Crocoblock plugins. Skills are written against plugin
source + live verification, not guesswork. See `README.md` for the full skill list and
`docs/principles.md` / `docs/authoring-guide.md` for authoring rules.

## Key conventions

- Every skill has `SKILL.md` + `TEST-REGIMEN.md` + `tests.php` — do not write a new
  skill without all three.
- Every fact must be verified against source or a live site. If something is inferred,
  say so in the text rather than presenting it as fact (`docs/principles.md`).
- Test artifacts on the live site **must** be namespaced `agent_test_*` / `AGENT-TEST*`
  and kept (not deleted) after the test run — they prove a claim was live-verified.
- See `docs/test-harness-guide.md` before writing or running any test suite.
- `HANDOFF.md` is the running log of what's been done and what's still open — update it
  after any round that adds or changes skills.

## Connecting to a live site (only when asked)

Most work here — writing a skill, reviewing one, running the test harness locally — needs
no live WordPress site, and many contributors won't have one. **Do not start MCP setup on
your own initiative.** When the user explicitly asks to connect to a live JetEngine site,
or when an MCP call fails because no server is configured, walk them through
`docs/mcp-setup.md`.

Setup is a single gitignored `.mcp.json` holding the site URL and one `Authorization`
header value (`Basic <base64>` from an Application Password, or `Bearer <jwt>`). There is
no `.env` in this repo — Claude Code does not read one.

## Handling credentials

Never ask the user to paste a WordPress password, application password, or JWT into the
chat. Anything they type reaches the transcript, and telling yourself not to repeat it
afterwards doesn't undo that. Instead, point them at the `read -rsp` snippet in
`docs/mcp-setup.md` so the secret is typed into their own shell and never leaves it, and
have them paste only the derived base64 token — or better, edit `.mcp.json` themselves.

The same applies to commands: a `base64` call with the password as a literal argument
puts it in the tool call, which is displayed and persisted. Don't construct one.

If you do end up holding a token — because you read `.mcp.json` to debug a connection —
refer to it by name, never print it, and don't copy it into memory files, summaries, or
any file that isn't already gitignored.
