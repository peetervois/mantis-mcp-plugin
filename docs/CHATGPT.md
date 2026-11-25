# ChatGPT connection

This repository contains the **MantisBT server plugin** and the private
**Tauria Tasks** ChatGPT plugin source in
[`chatgpt/tauria-tasks`](../chatgpt/tauria-tasks/README.md).
The package declares the installed MantisBT MCP endpoint; it does not
deploy the PHP server or supply a user's credentials.

Each person connects with their **own MantisBT API token**. An OpenAI API key,
a shared MantisBT service account and a server-wide MCP token are not needed.

## Current ChatGPT web limitation

On 2026-11-25, ChatGPT displayed this uploaded MCP package as **Desktop only**.
Installation on the web failed before any API-token prompt. The saved package's
MCP declaration is not a registered ChatGPT remote-app connection.

For web use, first register the endpoint through **Plugins → + → Add custom MCP
server** in the intended workspace. Check which authentication methods that flow
supports, then bind Tauria Tasks to the verified registered app. Do not guess an
app ID or remove the connection merely to make an empty plugin install.

The endpoint requires a personal MantisBT API token. **No authentication** is
not a workaround. If the web connection supports only OAuth, the server needs a
compatible OAuth flow before the connection can be completed. An API-key field
in a package does not establish support in the ChatGPT web connection flow.

The supplied logo is present in the saved package. ChatGPT still displayed its
default blue icon after the icon references were updated; the rendered icon
has not been fixed or verified.

### Legacy Apps administration

OpenAI also documents a separate administrator route:

1. Open [Admin console](https://admin.openai.com/) and select **Tauria OÜ**.
2. Open **Plugins → Manage Legacy Apps**.
3. In the Apps interface, use **Create** to configure a remote MCP app.
4. Enter the endpoint and inspect the authentication choices in that form.

The corresponding legacy path from ChatGPT is **Workspace settings → Apps →
Create**. This is a different entry point from the Plugins plus-button form.
The user recalls a previous Bearer-token setup; support for that method in the
retained Apps form still needs to be verified. Do not assume the two forms have
identical options or add OAuth solely because the newer form lacks API keys.

After a successful tool scan, retain the verified app ID so the plugin can use
the registered remote connection. App owners can edit the app name and logo
through its **Manage** menu when those controls are available.

## Portable MCP package

To build the Tauria Tasks package for the target installation:

```bash
python3 scripts/package-chatgpt.py
```

The output is `dist/tauria-tasks-0.1.1.zip`. Its endpoint is
`https://central.tauria.ee/mantis/api/rest/plugins/MantisMcp/mcp`.
This package can be used by clients that support its remote MCP and API-key
declarations. It is not yet a working ChatGPT web integration.

For another MantisBT installation, adapt the package's `mcp.json` URL and verify
that server before connecting.

Use the installed MCP endpoint, for example:

`https://mantis.example.com/api/rest/plugins/MantisMcp/mcp`

For a client supporting API-key authentication, configure the Bearer scheme and
enter the personal token through that client's secure credential setup.
The credential is not part of the plugin package and must not be inserted into
the URL, chat messages, tool arguments or the manifest.

The portable MCP declaration has this structure:

```json
{
  "$schema": "https://agent-plugins.org/schemas/1.0.0/mcp.schema.json",
  "mcpServers": {
    "mantis": {
      "type": "streamable-http",
      "url": "https://mantis.example.com/api/rest/plugins/MantisMcp/mcp",
      "extensions": {
        "com.openai": {
          "auth": {
            "type": "api_key",
            "headerScheme": "bearer"
          }
        }
      }
    }
  }
}
```

This is a configuration example, not an already connected ChatGPT package.
Replace the host and verify the live endpoint before packaging it.
The complete ChatGPT installation/credential flow still needs to be tested
through a supported registered remote connection. The public submission portal has separate
authentication limitations and currently does not support API-key declarations
in its connection form.

## Verify as each user

Start with requests such as:

- “Which MantisBT account am I connected as?”
- “List the projects I can access.”
- “Show issue 94.”

Connect a second account separately and verify that its project/issue access
matches its normal MantisBT access. Revoke a test token in MantisBT and verify
that its MCP connection receives HTTP 401.

## References

- [OpenAI: custom MCP connections and retained Apps administration](https://help.openai.com/en/articles/12584461-connecting-custom-mcp-servers-in-chatgpt)
- [OpenAI: package your plugin and configure MCP authentication](https://developers.openai.com/plugins/build/plugins)
- [OpenAI: connect and test your plugin](https://developers.openai.com/plugins/deploy/connect-chatgpt)
- [MantisBT 2.26.4 authentication middleware](https://github.com/mantisbt/mantisbt/blob/release-2.26.4/api/rest/restcore/AuthMiddleware.php)
