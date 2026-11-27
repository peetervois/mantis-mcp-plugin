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
output is a private mode-0600 file, published only after complete verification.
There is no default total-file or total-input cap. For large downloads, capture
one chunk object per line and stream validation/assembly with bounded client memory:

```bash
python3 scripts/assemble-attachment.py chunks.jsonl --json-lines --output-dir downloads
```

JSON Lines has a per-chunk envelope budget, with no cap on the sum of lines.
The legacy JSON-array mode still loads the input JSON into memory. Optional
`--max-bytes` sets an explicit local file cap. Temporary output is removed on
handled validation/write errors. An abrupt client shutdown can leave a local
`.mantis-download-*` file; discard it before starting another assembly. Saving
uses an atomic exclusive hard link in the destination filesystem to avoid
publishing partial bytes or overwriting existing files. File data received
through MCP remains subject to the caller's usual file-storage and sharing
choices; do not commit private downloaded attachments or chunk captures.

## Limits and permissions

From 0.7.0 there is no default MCP total-file cap, so existing accessible
attachments larger than 5 MiB or the former 8 MiB ceiling can be downloaded in
the same bounded chunks. A new upload limit does not prohibit downloading an
older attachment. To deliberately cap downloads, configure a positive integer
inside the existing PHP block in `config/config_inc.php`:

```php
$g_plugin_MantisMcp_max_download_bytes = 16777216; // Optional explicit 16 MiB cap.
```

Leave that override unset, or set it to `false`, for no MCP total-file cap.
Existing positive overrides stay enforced before the native content read;
there is no fixed hard ceiling or minimum floor imposed on an explicit cap.
The native REST handler still reads a permitted file in full on each request.
Server PHP memory and execution/storage limits therefore remain practical
constraints; bounded MCP output does not make the native read memory-bounded.
Malformed native content must fit the attachment's verified metadata size and
pass canonical encoding, exact length and full SHA-256 checks before output.

The plugin checks issue access first, verifies the attachment belongs to that
issue, applies native view/download thresholds and checks private-note access.
It supplements the 2.26.4 REST handler's attachment-view checks with download
and private-note checks before invoking the handler. Token revocation applies
to every resumed request. No cross-request file or account cache is used.

## Deploy and verify

Replace the installed `plugins/MantisMcp` directory with the current package and
follow the [installation update steps](INSTALL.md). Discover the tools through
the existing Tauria Tasks connection and confirm
`mantis_download_attachment` appears. Keep the working API-key remote connection.

Then request an image attachment from an accessible test issue in a fresh Work
discussion. Verify the saved image and size, and record its SHA-256. Check another account cannot
download inaccessible issues or private-note attachments.

Local tests verify binary reconstruction, native file-permission helpers and
client materialization against test data. Deployment and an authenticated live
download are separate acceptance checks; a local test is not an end-to-end
production verification.
