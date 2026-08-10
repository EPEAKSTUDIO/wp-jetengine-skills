# CLAUDE.md — JetEngine Skills repo

## Credential security — read before handling any secrets

**Never store credentials, tokens, passwords, or any value from `.env` in:**
- Your memory files (`.claude/projects/.../memory/`)
- Conversation summaries or compact notes
- Any file that could be committed (check `.gitignore` before writing)
- Tool call outputs printed to the user (mask tokens; show variable names, not values)

When you need to reference a credential in a response, name the variable
(`JETENGINE_BASIC_AUTH`) — never print its value. If you accidentally see a raw
credential in a tool result, do not repeat or log it.

---

## MCP server setup (do this first)

### Step 1 — check for `.env`

```bash
ls .env 2>/dev/null && echo "EXISTS" || echo "MISSING"
```

**If `.env` EXISTS**, load it and skip to Step 2:

```bash
set -a; source .env; set +a
```

Then check that `JETENGINE_BASIC_AUTH` is non-empty:

```bash
echo "${JETENGINE_BASIC_AUTH:+set}"
```

If it's empty (the user filled in `.env` but left `JETENGINE_BASIC_AUTH` blank), compute
and write it back — see Step 1b below.

---

**If `.env` is MISSING**, ask the user:

> "What is the URL of the WordPress site you want to connect to?
> (e.g. `https://my.example.com` — no trailing slash, no path)"

Then ask for their WordPress admin username and an
[Application Password](https://wordpress.org/documentation/article/application-passwords/)
(wp-admin → Users → Profile → Application Passwords → Add New).

**Step 1b — write `.env` from the answers (never echo the values back):**

Compute the token and write only `WP_SITE_URL` and `JETENGINE_BASIC_AUTH` to `.env`.
The raw username and password are used once to derive the token and then discarded —
they must not be written to any file.

```bash
# Run this as a single shell block so raw credentials never appear in output
WP_SITE_URL="<URL the user gave>"
JETENGINE_BASIC_AUTH=$(echo -n "<username>:<app_password>" | base64)

cat > .env << EOF
WP_SITE_URL=${WP_SITE_URL}
JETENGINE_BASIC_AUTH=${JETENGINE_BASIC_AUTH}
EOF

echo ".env written — token stored, raw credentials discarded"
```

Do **not** print the token or password value anywhere in your response.

---

### Step 2 — check for `.mcp.json`

```bash
ls .mcp.json 2>/dev/null && echo "EXISTS" || echo "MISSING"
```

**If `.mcp.json` is MISSING**, generate it from `.env`:

```bash
set -a; source .env; set +a
cat > .mcp.json << EOF
{
  "mcpServers": {
    "jetengine": {
      "url": "${WP_SITE_URL}/wp-json/jet-engine/v1/mcp/",
      "headers": {
        "Authorization": "Basic ${JETENGINE_BASIC_AUTH}"
      }
    }
  }
}
EOF
echo ".mcp.json written"
```

**If `.mcp.json` EXISTS**, skip unless the user reports a connection error.

---

### Step 3 — verify the connection

```bash
set -a; source .env; set +a
curl -s -X POST \
  -H "Authorization: Basic ${JETENGINE_BASIC_AUTH}" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2024-11-05","capabilities":{},"clientInfo":{"name":"claude-code","version":"1.0"}}}' \
  "${WP_SITE_URL}/wp-json/jet-engine/v1/mcp/"
```

A `200` response containing `"serverInfo":{"name":"Crocoblock Client MCP Server",...}`
confirms success. Report only the `serverInfo.name` and `protocolVersion` to the user —
do not print the full response if it would echo the Authorization header.

---

### Step 4 — reload MCP servers

Tell the user to open `/mcp` in Claude Code (or restart) so Claude Code picks up the
updated `.mcp.json`.

---

## What this repo is

A collection of verified Agent Skills (`skills/*/SKILL.md`) documenting real, non-obvious
internals of JetEngine and related Crocoblock plugins. Skills are written against plugin
source + live verification, not guesswork. See `README.md` for the full skill list and
`docs/principles.md` / `docs/authoring-guide.md` for authoring rules.

## Key conventions

- Every skill has `SKILL.md` + `TEST-REGIMEN.md` + `tests.php` — do not write a new
  skill without all three.
- Test artifacts on the live site **must** be namespaced `agent_test_*` / `AGENT-TEST*`
  and kept (not deleted) after the test run — they prove a claim was live-verified.
- See `docs/test-harness-guide.md` before writing or running any test suite.
- `HANDOFF.md` is the running log of what's been done and what's still open — update it
  after any round that adds or changes skills.
