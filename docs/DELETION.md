# Attachment deletion

`mantis_delete_attachment(issue_id, attachment_id)` permanently removes one
existing attachment. It requires the existing write-tool setting and has
destructive/non-idempotent annotations. Use it only for an explicitly requested
deletion or cleanup of a disposable file created for an authorized test.

Read the issue and attachment metadata first. The adapter checks native issue
access, membership of the file in that issue, attachment and private-note
visibility, read-only state and native delete thresholds/own-file rules. Having
delete permission does not grant visibility into a private note. Download
permission is not required just to delete a visible file, and the download size
limit does not prevent deleting a large attachment.

MantisBT 2.29's IssueFileDeleteCommand handles deletion when available. On the
supported older release, the adapter invokes the native file_delete API after
the same explicit ownership/visibility/delete-permission preflight. DATABASE
records or DISK files are removed by native storage code. The adapter clears
the attachment cache and verifies the id is absent before returning
`issue_id`, `attachment_id` and `deleted: true`.

No file contents, server paths, arbitrary URLs or alternate credentials are
accepted. Missing, wrong-issue and inaccessible file ids fail without revealing
other attachments. A later error can leave an uncertain result; reread the
issue before deciding whether to retry. Deletion has no undo operation.

For live acceptance, upload a disposable PNG to an editable test issue, verify
its bytes, delete only that new attachment, then confirm it is absent and cannot
be downloaded. Check that an inaccessible private-note attachment and a caller
without delete permission remain denied. Existing user attachments are not
disposable test data.
