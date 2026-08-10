# Connecting to a live JetEngine site over MCP

JetEngine ships its own MCP server at `wp-json/jet-engine/v1/mcp/`, introduced in
**JetEngine 3.8.0** alongside the Command Center. Point an agent at it and it can call
`tool-add-cct`, `tool-add-query`, `resource-get-configuration` and friends directly
instead of hand-rolling REST calls. See
[`skills/jetengine-mcp-tools/SKILL.md`](../skills/jetengine-mcp-tools/SKILL.md) for what
every tool actually does.

This is optional. Nothing else in this repo needs it — you can write and review skills
without a live site.

## Before you start

- **JetEngine 3.8.0 or newer.** (3.8.0 is where Crocoblock's release announcements put
  the MCP server and Command Center; the plugin's own `changelog.txt` isn't
  web-readable, so that's the sourcing. Verified working on 3.8.13.)
- **The server is on by default.** Two independent options gate it, and both default to
  `true`: `jet-engine-misc-settings['enable_features_api']` (the whole Features API
  layer) and `enable_mcp_server` (the MCP protocol endpoint specifically). If every tool
  call 404s, check those before assuming you got a tool name wrong —
  see `SKILL.md` "Architecture".
- **The account must have `manage_options`** (Administrator). `Feature::check_permissions()`
  defaults to that capability and no core tool overrides it, so there is no
  reduced-privilege mode. A token scoped to a lesser role authenticates fine and then
  403s on every tool.

## Step 1 — get a credential

JetEngine does not parse the `Authorization` header itself. It calls
`current_user_can( 'manage_options' )` and trusts whatever WordPress resolved through the
`determine_current_user` filter. That means **any** WordPress auth method works. Two
practical ones:

### Option A — Application Password (recommended default)

Built into WordPress core, and it doesn't expire.

1. `wp-admin → Users → Profile → Application Passwords → Add New`. Name it "Claude Code".
2. Copy the generated password (`xxxx xxxx xxxx xxxx xxxx xxxx`).
3. Base64-encode `username:password`:

   ```bash
   read -rp "WP username: " u; read -rsp "App password: " p; echo
   printf '%s' "$u:$p" | base64 | tr -d '\n'; echo
   unset u p
   ```

   `tr -d '\n'` matters — GNU `base64` wraps at 76 columns, so a long username produces a
   token with a newline in it.

Your header value is `Basic <that string>`.

### Option B — JWT bearer token

If your site already issues per-user JWTs — via [AAM](https://wordpress.org/plugins/advanced-access-manager/),
[JWT Authentication for WP REST API](https://wordpress.org/plugins/jwt-authentication-for-wp-rest-api/),
miniOrange, or similar — that works too, verified end to end against a JetEngine 3.8.13
site on 2026-08-10 (`initialize`, `tools/list`, and a real `tools/call`). Those plugins
hook `determine_current_user` for `Authorization: Bearer <jwt>`, which is the same door
Application Passwords come through — JetEngine can't tell the two apart.

Your header value is `Bearer <the jwt>`.

Two things to know before choosing this: **JWTs expire**, so a token pasted into
`.mcp.json` will start 401ing on its own schedule with no obvious cause, and the
`manage_options` requirement still applies — a per-user token scoped to a limited role
is exactly the case that 403s.

## Step 2 — write `.mcp.json`

`.mcp.json` is gitignored. Copy the template and fill in both values:

```bash
cp .mcp.json.example .mcp.json
```

```json
{
  "mcpServers": {
    "jetengine": {
      "type": "http",
      "url": "https://your-site.example.com/wp-json/jet-engine/v1/mcp/",
      "headers": {
        "Authorization": "Basic ZXhhbXBsZTpzZWNyZXQ="
      }
    }
  }
}
```

Swap `Basic …` for `Bearer …` if you went with Option B — nothing else changes.

> **Prefer to keep the token out of the file?** Claude Code expands `${VAR}` and
> `${VAR:-default}` inside `.mcp.json`, including in `headers`. Set
> `"Authorization": "${JETENGINE_MCP_AUTH}"` and export the full header value
> (`export JETENGINE_MCP_AUTH="Basic ZXhhbXBsZTpzZWNyZXQ="`) before launching Claude
> Code, e.g. from your shell profile or `direnv`. The variable is read from the
> environment of the `claude` process — **a `.env` file in this repo is not loaded**, so
> a token that only lives in `.env` never reaches the header. When a referenced variable
> is unset, Claude Code still loads the config and sends the literal text `${VAR}` as the
> header value, which reads as a plain 401 from WordPress; the only hint is a
> missing-variable warning in `claude mcp list`.

## Step 3 — verify

```bash
curl -s -o /tmp/mcp-probe.json -w '%{http_code}\n' -X POST \
  -H "Authorization: Basic PASTE_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2024-11-05","capabilities":{},"clientInfo":{"name":"claude-code","version":"1.0"}}}' \
  "https://your-site.example.com/wp-json/jet-engine/v1/mcp/"
```

Swap `Basic PASTE_TOKEN` for `Bearer <jwt>` to check a JWT — verified working
(2026-08-10, JetEngine 3.8.13).

A `200` and this body mean you're connected:

```json
{"jsonrpc":"2.0","id":1,"result":{"protocolVersion":"2025-03-26",
 "capabilities":{"tools":{"listChanged":false}},
 "serverInfo":{"name":"Crocoblock Client MCP Server","version":"1.0.0"}}}
```

The server answers `2025-03-26` whatever `protocolVersion` you send, so don't read that
back as an echo of your request. It also replies plain JSON and does **not** require an
`Accept: application/json, text/event-stream` header, even though the streamable-HTTP
transport normally implies one — a missing `Accept` is not your problem if this fails.

`curl -s` alone prints only the body, so without `-w` you cannot see the status code — the
distinction matters because the failure body is short enough to skim past:
`{"code":"rest_forbidden","message":"You cannot access this resource."}` with a **401**.

## Step 4 — enable the server in Claude Code

Open `/mcp` (or restart). `jetengine` shows as `⏸ Pending approval` until you approve it
once — that prompt is the security gate on project-scoped MCP servers and is worth
keeping.

Don't commit `enableAllProjectMcpServers` to `.claude/settings.json` to skip it. Since
Claude Code v2.1.196 a cloned repo can't approve its own servers anyway — the setting is
ignored until you accept the workspace trust dialog — and once you have trusted the
folder it silently auto-approves every *future* `.mcp.json` too, which is a bigger
concession than the one click it saves.

## Troubleshooting

**401 with credentials you know are right.** Many Apache/CGI and nginx+PHP-FPM stacks
strip the `Authorization` header before PHP sees it. This hits Basic and Bearer equally
and is the most common cause. Apache:

```apache
SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1
```

nginx + PHP-FPM:

```nginx
fastcgi_param HTTP_AUTHORIZATION $http_authorization;
```

**403 on every tool, but `initialize` succeeds.** The account lacks `manage_options`.

**404 / no route at all.** `enable_features_api` or `enable_mcp_server` is off, or the
site is on JetEngine < 3.8.0.

**Worked yesterday, 401 today, nothing changed.** If you used a JWT, it expired.
