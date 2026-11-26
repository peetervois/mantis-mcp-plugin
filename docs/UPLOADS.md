# Attachment uploads

Version 0.4.1 fixes a false failure after attaching to an existing note: the
adapter now calls the native `bugnote_date_update` helper. On 0.4.0, the file
can already be saved even though the response reports an unconfirmed upload.
Read the issue before retrying to avoid duplicating that attachment.

MantisMcp 0.3.0 adds `mantis_upload_attachment` to the existing authenticated MCP
endpoint. It attaches one file to an existing accessible issue as the token
owner. Enable the existing write tools first:

```php
$g_plugin_MantisMcp_enable_write_tools = ON;
```

This exposes a write operation; it does not grant MantisBT upload permission.
The issue's access, read-only state, upload settings and native file-type rules
still apply. Without a note id, files attach to the issue itself (`note_id: 0`),
inheriting its visibility. Version 0.4.0 also supports new and existing notes.

## Images and files in notes

To create a note and file together, call `mantis_add_note` with `issue_id`,
`text`, optional `private`, and an `attachment` object containing the four file
fields `filename`, `mime_type`, `content_base64` and `sha256`. Omit `private` to
keep native default note visibility. Native IssueNoteAddCommand handles authorship,
note/add/upload/private permissions and moderation. Where the native version
supports moderation, notes requiring moderation cannot silently bypass it to
attach files. The result contains the new note id and verified attachment
receipt; do not retry an uncertain response as if no note had been created.

To attach a file to an existing note, pass its positive `note_id` to
`mantis_upload_attachment`. The helper supports this directly:

```bash
python3 scripts/prepare-attachment.py screenshot.png --issue-id 123 --note-id 456 --output upload.json
```

The note must belong to the issue and be visible and editable by the caller.
The adapter applies the same bugnote_user_edit_threshold for the author or
update_bugnote_threshold for other authors as native bugnote_update.php, plus
issue/upload/file checks. It uses native file_attach_files with the note id,
updates the note modification timestamp and signals the native edit event.
Existing text, author and visibility are preserved. The attachment inherits
the note's access restrictions; it is never silently moved to issue level.

For either mode, download the returned attachment id through the normal MCP
download tool and compare its original bytes/hash. Test private notes with an
independent account as well as a permitted owner. Test storage failures and
uncertain outcomes before assuming an entire note/file operation was rolled back.

## Prepare and submit an original file

Use code to read the user-selected original bytes and submit this object:

```json
{
  "issue_id": 123,
  "filename": "screenshot.png",
  "mime_type": "image/png",
  "content_base64": "<canonical base64 of the complete original file>",
  "sha256": "<64 lowercase hexadecimal SHA-256 characters>"
}
```

The values in angle brackets are explanatory placeholders, not valid payloads.
Compute both content fields from the original file. Do not generate substitute
image bytes or paste encoded content into chat. A server cannot read a path in
the client's workspace; there is no URL, server-path or reporter override.

A Python standard-library helper in the source repository prepares an exact
payload locally; it is not required on the PHP server:

```bash
python3 scripts/prepare-attachment.py screenshot.png --issue-id 123 --output upload.json
```

The helper has no network access or credentials. It writes a new mode-0600 JSON
file and refuses to overwrite an existing path or symlink. Read that file in
code and pass its object to `mantis_upload_attachment`; do not print its binary
payload into the conversation. Remove the local payload when it is no longer
needed and never commit private file bytes or payload captures.

The result contains `issue_id`, `attachment_id`, `note_id`, `filename`,
`mime_type`, `size`, `sha256` and `reporter_id`. It contains no file content or
server path. The server verifies the stored content hash before reporting
success. MantisBT detects MIME type from the file where possible, so its
returned MIME type can correct the caller's initial value. Read the issue back
and use `mantis_download_attachment` for a complete upload/download round trip.

## Limits

The decoded upload default is **512 KiB**; the hard maximum is **5 MiB**. Empty
files are rejected, matching native MantisBT behavior. Filenames are limited to
250 UTF-8 bytes and cannot contain directory separators, colons or controls.
MantisBT's allowed/disallowed file extensions are also enforced.

For larger files, adjust both plugin limits inside the existing PHP block in
`config/config_inc.php`, for example:

```php
$g_plugin_MantisMcp_max_upload_bytes = 5242880;
$g_plugin_MantisMcp_max_request_bytes = 8388608;
```

Base64 is about one third larger than the original bytes, and the JSON envelope
also needs room. The HTTP request default remains 1 MiB. PHP
`upload_max_filesize`, `post_max_size`, MantisBT `max_file_size`, web-server and
client limits can impose a lower bound. Raising the plugin limits does not
override those settings. Use `--max-bytes 5242880` with the preparation helper
only after configuring a compatible server/client request limit.

## Native upload implementation and failure handling

The adapter first invokes the normal REST issue-read handler. It then uses
the same `IssueFileAddCommand` as `rest_issue_file_add`, with a single
browser-style file object whose `browser_upload` is false. The native command,
`file_attach_files`, `file_add`, permissions, MIME detection and DATABASE/DISK
storage remain responsible for the write.

The REST wrapper's `files_base64_to_temp` uses permissive base64 decoding,
does not expose its temporary paths for cleanup, and returns no attachment id.
The adapter instead validates canonical base64/hash, creates its own exclusive
mode-0600 staging file, and removes it in `finally`, including native failures.
It clears MantisBT's request-local attachment cache after storage, identifies a
new file belonging to this issue and token owner, and checks its original bytes
before returning a compact receipt. It never accepts a caller-selected server
path or uses a separate network connection.

Uploads are **not idempotent**. Native MantisBT renames duplicate filenames,
for example `screenshot-2.png`; the receipt returns the actual filename. After a
timeout or uncertain result, read the issue and inspect attachment metadata
before deciding whether to retry. There is no automatic retry or claim of an
all-or-nothing rollback. Native persistence may have completed even if a later
step or verification failed.

## Deploy and verify

Replace the installed `plugins/MantisMcp` directory with the 0.3.0 package.
Upgrade the plugin in MantisBT management if offered. Refresh the existing
Tauria Tasks remote connection's approved tools and confirm
`mantis_upload_attachment` appears. Retain its existing personal API-token
authentication; no browser sign-in or additional credential is needed.

In a fresh discussion, supply a harmless PNG and explicitly request an upload
to a permitted test issue. Compare the receipt and issue metadata with the
original filename, size and SHA-256. Download the new attachment and compare its
original bytes. Test an account without issue/upload access and write tools
disabled. Live production upload remains pending deployment and client tool
refresh; local integration tests are not a production verification.
