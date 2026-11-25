# ChatGPT connection

This repository contains the **MantisBT server plugin**. Install it and verify
its HTTPS MCP endpoint before creating the corresponding ChatGPT plugin.
No ChatGPT plugin has been published or connected by this source repository.

Each person connects with their **own MantisBT API token**. An OpenAI API key,
a shared MantisBT service account and a server-wide MCP token are not needed.

## Connection

Use the installed MCP endpoint, for example:

`https://mantis.example.com/api/rest/plugins/MantisMcp/mcp`

Configure the connection for **API key authentication with the Bearer scheme**.
Enter the personal MantisBT token through the connection's credential setup.
The credential is not part of the plugin package and must not be inserted into
the URL, chat messages, tool arguments or the manifest.

For a private packaged ChatGPT plugin, the MCP declaration has this structure:

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
after the MantisBT-side deployment. The public submission portal has separate
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

- [OpenAI: package your plugin and configure MCP authentication](https://developers.openai.com/plugins/build/plugins)
- [OpenAI: connect and test your plugin](https://developers.openai.com/plugins/deploy/connect-chatgpt)
- [MantisBT 2.26.4 authentication middleware](https://github.com/mantisbt/mantisbt/blob/release-2.26.4/api/rest/restcore/AuthMiddleware.php)
