# Transfer lifetime and cleanup

Uploads default to a one-day idle deadline in `expires_at` (Unix seconds). Configure
the positive timeout in seconds inside the existing `config/config_inc.php` PHP block:

```php
$g_plugin_MantisMcp_upload_idle_seconds = 86400;
```

The returned `idle_timeout_seconds` describes the current setting. Successful
chunks extend it; status polling does not. An expired session cannot append or
finalize. Clients should inspect status after a timeout or lost connection,
then read the issue before starting another upload: interrupted finalization
may already have created the native file. No disconnect notification is needed.

Cleanup runs on authenticated MCP interactions, including reads with writes
disabled, and when an upload begins. It collects up to 100 expired staging
candidates, including old incomplete metadata and orphan temporary files. It
never waits for a busy session lock and never removes a native Mantis attachment
or note. Active sessions remain intact. Data deletion happens before metadata
deletion; failures retain the information needed to retry cleanup. Repeated
interactions drain larger backlogs. A small nonblocking maintenance lock prevents two
collectors racing, without serializing uploads or waiting on other sessions.

Cleanup is intentionally lazy. Without another interaction, expired files may remain
on disk. No cron job or background service is required. Completed receipts and
cancelled tombstones also use the configured timeout; losing them never rolls back a
native write. The existing private directory and shared filesystem/locking
requirements remain unchanged.

Downloads are stateless bounded reads. They create no remote download session or
server-side partial file. Each resumed request rechecks token, issue and note
access; the expected SHA-256 pins it to the same file. Client-side partial data
must be resumed or discarded by the client; the server cannot clean its files.

Existing absolute deadlines remain authoritative after a setting change; the
new timeout applies when a session starts or successfully advances. Local tests
include a configured one-second timeout with a real elapsed wait, plus aged
staging without changing the production clock. They verify
read-triggered collection, deadline rejection, busy/active session preservation,
orphan cleanup and absence of native attachments from unfinished uploads.

## Independent work

Each upload has its own opaque id, state, data file and lock. Different sessions
can progress on different issues while notes or other tool calls run. There is
no connection-wide active-upload slot. Operations on the same session fail as
busy while its lock is held, so clients inspect status and retry instead of
interleaving writes. Garbage collection skips those locks without waiting.

A local subprocess test holds one session's real filesystem lock while the
authenticated MCP harness uploads/finalizes a different file and adds a note.
It then releases the first lock and verifies that session resumes correctly.
This proves lock isolation separately from end-to-end tests with overlapping
client calls; it is not a throughput or multi-account load test.
