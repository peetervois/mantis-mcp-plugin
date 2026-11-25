# Mantis MCP Plugin

A native PHP plugin for MantisBT that exposes a stateless MCP Streamable HTTP
endpoint. Each caller authenticates with their own personal MantisBT API token.
The plugin uses MantisBT's existing REST authentication and REST handlers, so
operations run as the token owner with normal MantisBT permissions.

Target: **MantisBT 2.26.4**, PHP **7.4+**, with REST API enabled. Other MantisBT
versions have not yet been verified. No Node.js, separate service, database
tables, server-wide API token, or production Composer installation is required.

[Installation](docs/INSTALL.md) · [ChatGPT connection](docs/CHATGPT.md)

## Install

1. Copy the `MantisMcp` directory into your MantisBT `plugins/` directory.
   The result must be `plugins/MantisMcp/MantisMcp.php`.
2. Open **Manage → Manage Plugins** in MantisBT and install **Mantis MCP**.
3. Use this MCP endpoint, replacing the example host and MantisBT root:

   `https://mantis.example.com/api/rest/plugins/MantisMcp/mcp`

   Without URL rewriting, use:

   `https://mantis.example.com/api/rest/index.php/plugins/MantisMcp/mcp`

The endpoint is registered through `EVENT_REST_API_ROUTES`. It inherits the
existing MantisBT HTTPS/Apache/PHP configuration; no reverse proxy or extra
listening port is involved. The supplied `.htaccess` denies direct HTTP access
to the implementation directory without affecting the REST endpoint.

## Authentication

Generate a personal token under **My Account → API Tokens**. The MCP client
sends it on every request:

```http
Authorization: Bearer YOUR_PERSONAL_MANTISBT_API_TOKEN
```

Bare tokens are accepted for compatibility with MantisBT's REST conventions.
The plugin normalizes Bearer headers for MantisBT 2.26.4 only on its own MCP
endpoint. Other REST endpoints are unaffected.

Missing, invalid and revoked tokens return HTTP 401. Cookie-only and anonymous
access are rejected even when anonymous REST access is enabled. Disabled accounts
and API access restrictions are still handled by MantisBT. Impersonation headers
are rejected: MCP acts as the token owner.

Tokens are not configured in the plugin, cached, returned in tool results, or
written to plugin logs. There is no shared user-data cache. Responses use
`Cache-Control: no-store, private`. If a browser sends `Origin`, it must match
the origin of MantisBT's configured `path`.

Serve the endpoint over HTTPS, as you would the REST API carrying the same token.
An OpenAI API key is not involved.

## Tools

The default configuration exposes six read tools:

| Tool | Purpose |
|---|---|
| `mantis_get_current_user` | Identify the API-token owner. |
| `mantis_list_projects` | List accessible projects. |
| `mantis_get_project` | Read project details, categories and versions. |
| `mantis_get_issue_options` | Discover project-specific enum ids. |
| `mantis_list_issues` | List/filter accessible issues, up to 100 per page. |
| `mantis_get_issue` | Read an accessible issue and its permitted notes. |

To enable writes, add this inside the existing PHP block in
`config/config_inc.php`:

```php
$g_plugin_MantisMcp_enable_write_tools = ON;
```

This additionally exposes:

| Tool | Purpose |
|---|---|
| `mantis_create_issue` | Create an issue as the current user. |
| `mantis_update_issue` | Update supported fields using MantisBT's normal rules. |
| `mantis_add_note` | Add a note as the current user. |

Enabling the tools does not grant additional MantisBT permissions. A read-only
MantisBT account remains read-only. Write schemas do not accept another reporter,
an arbitrary REST operation, a URL, an upload path or a command to execute.
Deletion and file upload are not implemented in this version.

Use `mantis_get_project` for category ids and `mantis_get_issue_options` for
status/priority/severity ids before creating or updating issues.

An optional global request-size setting is available:

```php
$g_plugin_MantisMcp_max_request_bytes = 1048576; // Default: 1 MiB.
```

## Test the endpoint

Set `MANTIS_API_TOKEN` in your shell to a personal MantisBT token. For example,
enter it without placing the value in shell history:

```bash
read -rsp 'MantisBT API token: ' MANTIS_API_TOKEN
printf '\n'
export MANTIS_API_TOKEN
export MANTIS_MCP_URL=https://mantis.example.com/api/rest/plugins/MantisMcp/mcp
php tests/smoke.php
```

The smoke test only initializes the connection, lists tools, reads the token owner
and checks unauthenticated/invalid-token failures. It does not create or change
MantisBT records. To check a second account, repeat it with that account's token.
Optionally set `MANTIS_MCP_EXPECTED_USER_ID` to check the token owner, and
`MANTIS_MCP_HIDDEN_ISSUE_ID` to verify rejection of an issue that account must
not be able to read. Unset `MANTIS_API_TOKEN` after testing.

## Development and checks

Composer is used only to obtain Slim 3 for the development harness. Production
uses the Slim version already bundled with MantisBT.

```bash
composer install
git clone --depth 1 --branch release-2.26.4 \
  https://github.com/mantisbt/mantisbt.git ../mantisbt-2.26.4
MANTISBT_SOURCE=../mantisbt-2.26.4 composer test
python3 scripts/package.py
```

Tests use the actual MantisBT 2.26.4 `MantisPlugin` base class and
`AuthMiddleware` with real Slim HTTP requests and an in-memory test datastore.
They cover protocol handling, authentication, token revocation, account
separation, input bounds, REST dispatch and write-tool gating.
They do **not** replace an installation test against a real MantisBT database.
The integration has not yet been deployed to the target MantisBT instance.

The package is written to `dist/MantisMcp-0.1.0.zip`. It contains only the
installable plugin directory, its license, documentation and this README; no tokens, development
dependencies or test data are included.

## License

[BSD 3-Clause](LICENSE). Copyright © 2026 Peeter Vois.
