# Chunked attachment uploads

Version 0.6.0 adds private staging for an original file whose bytes do not fit
one MCP request. Any file type allowed by native MantisBT is supported. Enable
write tools as described in [installation](INSTALL.md), and read
`mantis_get_attachment_policy(issue_id)` first.

The assembled file inherits the issue project's MantisBT `max_file_size`.
There is no separate default MCP total-file cap. An explicitly configured
positive `max_upload_bytes` remains an administrator-selected cap. Native
MantisBT also applies PHP upload/post limits to the complete file; these are
preserved. Per-request and per-chunk bounds protect transport without capping
the sum of accepted chunks. Storage capacity and native PHP memory requirements
still matter during finalization and verification.

## Sequence

| Tool | Input and result |
|---|---|
| `mantis_begin_attachment_upload` | Original `issue_id`, `filename`, `mime_type`, byte `size` and whole-file `sha256`; returns opaque `upload_id`, `max_chunk_bytes` and `next_offset`. |
| `mantis_upload_attachment_chunk` | `upload_id`, byte `offset`, canonical `content_base64` and this chunk's `sha256`; returns progress and the next offset. |
| `mantis_get_attachment_upload` | Read owned session progress after interruption; no file bytes or new-note text are returned. Available with writes disabled. |
| `mantis_finish_attachment_upload` | `upload_id`; verifies complete size/hash, rechecks native permissions and rules, and saves one native attachment. Returns a verified receipt. |
| `mantis_cancel_attachment_upload` | `upload_id`; removes unfinished temporary data. It never deletes a native Mantis attachment or reverses a native write. |

1. Read and hash the user-selected original file in code. Begin one session.
   Optionally supply an existing editable `note_id`, or `new_note` containing
   `text` and optional `private`. Omit both to attach directly to the issue.
   New-note visibility defaults to native MantisBT when `private` is omitted.
2. Read at most the returned `max_chunk_bytes` starting at `next_offset`.
   Send only that chunk's base64 and SHA-256. Use the returned next offset until
   `received_bytes` equals the declared total size. The current maximum chunk
   is 256 KiB and can be lower under a smaller request budget.
3. Finish. Compare the receipt's original size and whole-file hash. Read the
   issue back and verify the new attachment/note metadata. Download through MCP
   for an exact round trip when its separate download limit permits it.

No native attachment or note is created before finalization. Native write
permissions, read-only state, extension rules, size and existing-note edit
permissions are rechecked as chunks arrive and at finalization. Native note
creation/private/moderation checks still run in the native command. Every
operation requires the same token owner's current issue access. Session ids
are not bearer credentials and do not grant another user access.

## Local preparation helper

The Python standard-library helper has no credentials or network access. It
hashes manifests incrementally and reads only a bounded part for chunk mode:

```bash
python3 scripts/prepare-attachment.py archive.zip --issue-id 123 --manifest --output manifest.json
python3 scripts/prepare-attachment.py archive.zip --upload-id UPLOAD_ID --offset 0 --length 262144 --output chunk-0.json
```

Replace `UPLOAD_ID` with the begin result and use its returned offset/limit on
subsequent calls. Pass the prepared JSON objects directly through the connected
MCP tools in code. Do not print encoded file content into chat. Each output is
a new private mode-0600 file; existing files/symlinks are never overwritten.
There is no default client total-file cap. Optional `--max-bytes` deliberately
caps manifest/inline preparation when desired. For new-note staging, add the
`new_note` object to the manifest in code before calling begin. Never change the
source file mid-session; complete hash verification rejects changed content.

## Retries and uncertain outcomes

After an interrupted chunk call, inspect session status. An exact replay of
already stored bytes is safe; changed bytes, gaps, oversized chunks and overlap
with new data fail. If a partial data write survived without its metadata,
status uses the actual stored byte count so the client can resume at that offset.
A stable per-session lock rejects concurrent operations instead of interleaving
their writes.

After a successful finish, repeated finish calls return the same verified
receipt without another native write, while the session exists and the native
file remains visible. Before any native write, the server persists a
`finalizing` barrier. If the write outcome cannot be confirmed, the session
stays `finalizing` or `unconfirmed` and will not execute it again. Inspect the
issue and notes before starting another upload. Native persistence may already
have happened; this is not an all-or-nothing transaction. Cancellation of such
a session reports `native_write_unconfirmed: true` and only discards staging.
Beginning a new session is not idempotent and can create a duplicate if that
later session is also finalized.

## Staging lifecycle and deployment

Staging lives in a private installation-specific directory below PHP's system
temporary directory. The server generates all paths; tools accept no client
path, server path, URL, owner override or arbitrary REST operation. Directory
permissions are 0700; files are 0600. Bytes stay on disk between requests and
are checked against the original whole-file SHA-256 before native storage.
Metadata includes note text until finalization/cancellation, so protect the
temporary filesystem like the other application data.

Sessions expire after the configured idle timeout (one day by default) following
creation or the last successful chunk append.
Polling alone does not extend them. Completed receipts and cancelled tombstones
use the same configured timeout for safe retries. A new begin opportunistically
collects up to 100 old staging candidates. An expired session cannot resume.
Authenticated MCP interactions also check for expired staging, including reads.
Cleanup is deliberately lazy: without later traffic, expired staging can remain
on disk. No cron job or separate background service is needed. See
[session lifecycle](SESSION-LIFECYCLE.md) for timeout and concurrency checks.
Never remove active staging during deployment. All PHP workers serving one
installation need the same private writable temporary directory and reliable
filesystem locks/atomic rename; independent hosts need shared compatible
staging or session affinity. Losing staging loses resumability and receipt
history, so read the issue before retrying an upload after such a loss.

Local integration tests use the native MantisBT 2.26.4 and 2.29.0 commands/file
APIs with fixture storage. They cover files larger than the old MCP ceiling,
DATABASE/DISK persistence, chunk and final hash checks, retry/lock handling,
permission changes, expiry, notes and uncertain native writes. The Python client
also prepares a file larger than one request and verifies an exact MCP round
trip through the local harness. Deployment and live acceptance remain separate.
