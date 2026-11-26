# Installation on Apache/PHP

The plugin runs through the existing MantisBT REST API. It does not require
Node.js, a separate MCP process, Composer on the server, or a proxy port.

## 1. Install the plugin directory

Copy the repository's `MantisMcp` directory into the MantisBT `plugins/` directory.
For an installation at `/var/www/html/mantis`, the entry point must be:

```text
/var/www/html/mantis/plugins/MantisMcp/MantisMcp.php
```

Alternatively, extract `MantisMcp-0.5.0.zip` into `plugins/`. Build the package on
a development machine using `python3 scripts/package.py`, or download it from
the GitHub Actions artifacts for the branch being tested.

The server artifact now contains the installable `MantisMcp/` folder directly;
there is no inner installer ZIP to extract. Preserve hidden files, including
`MantisMcp/.htaccess`, when copying. Replace the plugin directory in the active
MantisBT installation and upgrade in plugin management if offered. Restart the
PHP handler serving MantisBT (for PHP-FPM, typically `systemctl restart php-fpm`)
after deployment so OPcache cannot keep old classes. Verify the displayed plugin
version and discover the tools through the existing MCP connection. If new
tools remain absent after a PHP-FPM restart, reapply the plugin installation
using uninstall/install in MantisBT's plugin manager, then discover them again.
This does not require a new personal API token.
Plugin updates do not require a new API token or a separate browser login.

Log in to MantisBT as an administrator, open **Manage → Manage Plugins**, and
install **Mantis MCP**.

## 2. Connect to the MCP endpoint

For the target installation, the endpoint after installation is:

```text
https://central.tauria.ee/mantis/api/rest/plugins/MantisMcp/mcp
```

If URL rewriting is unavailable:

```text
https://central.tauria.ee/mantis/api/rest/index.php/plugins/MantisMcp/mcp
```

Each user generates a personal token in **My Account → API Tokens**, then supplies
that token through their own MCP client's connection credential settings:

```http
Authorization: Bearer PERSONAL_MANTISBT_API_TOKEN
```

Do not add a user token or shared MCP token to the plugin's server configuration.
The ChatGPT connection configuration is described in [CHATGPT.md](CHATGPT.md).

## 3. Enable writes when needed

The initial installation exposes read tools. To enable issue creation, updates
and notes, add this to the existing PHP block in `config/config_inc.php`:

```php
$g_plugin_MantisMcp_enable_write_tools = ON;
```

The caller's normal MantisBT permissions still apply.

## 4. Verify

Opening the endpoint in a browser is not an MCP test. The endpoint uses
authenticated HTTP POST requests:

- No token: HTTP **401**.
- Valid token with GET: HTTP **405** and `Allow: POST`.
- Valid token with MCP initialize POST: HTTP **200** with a JSON-RPC response.

Run the read-only `tests/smoke.php` helper as described in the README. Test a second
account separately and check that its visible projects and issues match MantisBT.

If authorization headers are stripped by Apache/FastCGI, restore forwarding in
the same configuration used by the existing REST API. The MCP endpoint follows
the same route and middleware, so a working authenticated REST endpoint is the
appropriate reference.

## Earlier standalone-server configurations

This plugin uses a path beneath `/mantis/api/rest/`. An earlier `/mantis-mcp`
`ProxyPass` mapping and a separate `mantis-mcp` systemd service are not part of
this implementation. They do not need to be running to use this PHP plugin.

The existing `Alias /mantis /var/www/html/mantis` and its `Directory` configuration
serve the plugin's REST endpoint as well.

## Updating from 0.4.0

Version 0.4.1 fixes false failure responses after existing-note uploads and
relationship deletion. On 0.4.0, these writes can already have completed despite
the error. Read the issue and current relationships before retrying. Replace the
plugin with 0.4.1 and restart the PHP handler; no new token or schema change is
required for this patch.

## Updating to 0.5.0

Deploy the replacement plugin directory and follow the tool discovery steps
above to discover mantis_get_attachment_policy.
There are eleven read tools and seven optional writes. Use an accessible issue
to discover the native file-extension policy and separate size limits before
uploading any file type. The improved source-checkout smoke helper validates
capabilities and behavior; a different version label alone is not a failure.

## Updating to 0.6.0

Uploads now inherit the native MantisBT limit instead of a fixed MCP ceiling.
Remove an old explicit `max_upload_bytes` override to inherit it; an existing
positive override remains an intentional cap. Five new session tools support
bounded chunks, status, finalization and cancellation. There are twelve reads
and eleven optional writes. See [chunked uploads](CHUNK-UPLOADS.md) for staging,
permissions, native size checks and restart/multi-worker requirements. Download
limits remain separate. Verify exact bytes on a disposable permitted file before
using the new upload path for real work.
