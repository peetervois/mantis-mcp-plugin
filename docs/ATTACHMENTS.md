# Attachment downloads

Mantis MCP 0.2.0 adds the read-only `mantis_download_attachment` tool. It is
available with write tools disabled. Every call uses the existing MCP
connection's personal MantisBT token and rechecks issue, attachment and note
permissions. No browser session or additional credential is needed.

## Download through Tauria Tasks

1. Read the issue with `mantis_get_issue` and locate the attachment id in the
   issue's attachments or a note's attachments. Supply both the issue id and
   attachment id to `mantis_download_attachment`.
2. Read the returned `structuredContent` programmatically. It contains a chunk
   in `content_base64`, the filename, normalized MIME type, total `size`,
   SHA-256 hash, offset and number of bytes returned.
3. While `complete` is false, request `next_offset` with `expected_sha256` set
   to the first response's hash. Each chunk is at most 65,536 original bytes.
   Keep chunks in order; discard all partial bytes if any call fails or the
   identity/hash changes.
4. Decode and concatenate the chunks, verify the total size and SHA-256, and
   save the file using its filename. Do not paste encoded content into chat or
   print full tool responses containing file data.

This transport uses the same JSON tool-result path as existing tools. The
original file bytes are returned, not just metadata or a link requiring another
login. The text content block contains metadata only; binary chunks live in
`structuredContent`. There are no public URLs or credentials in URLs.

For example, after discovering attachment 456 on accessible issue 123:

```json
{"issue_id": 123, "attachment_id": 456}
```

Replace these example ids with the values from the issue response. The caller
must be able to read the issue and note; ids do not confer permission.

## Materialize chunks in a client workspace

Capture the downloaded `structuredContent` objects as a JSON array in a local
`chunks.json`. Avoid printing `content_base64` to the conversation. The supplied
Python standard-library helper validates all chunk metadata, order, sizes and
the complete-file hash before saving the file:

```bash
python3 scripts/assemble-attachment.py chunks.json --output-dir downloads
```

The output directory must already exist. The helper makes no network calls,
uses no credentials and refuses to overwrite existing files or symlinks. Its
input limit is 32 MiB and its decoded-file limit is 8 MiB. File data received
through MCP remains subject to the caller's usual file-storage and sharing
choices; do not commit private downloaded attachments or chunk captures.

## Limits and permissions

The default maximum original file size is 5 MiB. Configure it in the existing
PHP block in `config/config_inc.php` if needed:

```php
$g_plugin_MantisMcp_max_download_bytes = 5242880;
```

The setting is clamped to 1 KiB–8 MiB. Oversized metadata is rejected before the
native REST handler reads file content. MantisBT's native handler reads a
permitted file in full on every chunk request, so the hard limit bounds the
memory used by that operation. Files larger than the limit fail explicitly.

The plugin checks issue access first, verifies the attachment belongs to that
issue, applies native view/download thresholds and checks private-note access.
It supplements the 2.26.4 REST handler's attachment-view checks with download
and private-note checks before invoking the handler. Token revocation applies
to every resumed request. No cross-request file or account cache is used.

## Deploy and verify

Replace the installed `plugins/MantisMcp` directory with the 0.4.1 package and
upgrade the plugin in MantisBT's plugin manager if offered. Refresh the existing
Tauria Tasks connection's tool list using its management settings; confirm
`mantis_download_attachment` appears. Keep the working API-key remote connection.

Then request an image attachment from an accessible test issue in a fresh Work
discussion. Verify the saved image and size, and record its SHA-256. Check another account cannot
download inaccessible issues or private-note attachments.

Local tests verify binary reconstruction, native file-permission helpers and
client materialization against test data. Deployment and an authenticated live
download are separate acceptance checks; a local test is not an end-to-end
production verification.
