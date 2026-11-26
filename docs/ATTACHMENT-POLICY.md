# Attachment policy discovery

`mantis_get_attachment_policy(issue_id)` is read-only and works with write tools
disabled. It first verifies native access to the issue, then retrieves only
`allowed_files`, `disallowed_files` and `max_file_size` through the native
configuration REST handler, scoped to that issue's project and the token owner.
It accepts no configuration names, other user id, URL or project override.

All attachment types are supported when permitted: documents, archives, text,
logs, binary files and images use the same upload/download paths. PNG examples
in other guides do not imply an image-only restriction.

## Returned fields

- `issue_id` and `project_id` identify the verified scope.
- `policy.allowed_files` and `policy.disallowed_files` each contain `available`,
  `value` (the native string or null) and `extensions` (normalized tokens or null).
- `policy.max_file_size` contains `available` and an integer byte `value`, or null.
  Valid decimal-string configuration values are normalized to integers.
- `rules` reports whether both extension settings are known, case handling,
  deny precedence and whether other extensions are allowed. The last value is
  null if either extension setting is unavailable.
- `limits.plugin_upload_bytes` reports the inherited native size or explicit
  MCP cap. With inheritance and an unavailable native setting, it is null.
- `limits.upload_limit_source` is `mantis` by default, or `explicit` when an
  administrator configured a positive MCP cap. `configured_upload_bytes` is
  null for inheritance, otherwise that explicit cap.
- `limits.mcp_request_bytes` bounds each HTTP request; `upload_chunk_bytes`
  bounds one decoded chunk. Neither is a total assembled-file limit.
- PHP upload/post byte settings and `decoded_upload_upper_bound_bytes` use
  the same native limits as the uploader. The upper bound is null when the
  native maximum is unavailable. Native MantisBT checks these PHP settings on
  the assembled file too. Other server/client/storage limits still apply.

Native semantics are preserved: `disallowed_files` takes precedence over
`allowed_files`, with case-insensitive extension comparison. An explicitly
empty/blank allowlist permits any extension except exclusions. A missing,
private or invalid setting is unavailable and must not be treated as empty.
These settings describe filename extensions, not MIME types. Original raw
strings remain present. Normalized tokens use lowercase and retain significant
spaces, leading dots and empty tokens inside nonblank comma-separated values,
because native file_type_check does not trim individual tokens.

For example, `allowed_files = "PDF,zip"` and `disallowed_files = "pdf"`
permit ZIP but reject PDF, regardless of filename extension case. An empty
allowlist with unavailable exclusions does not establish a known complete policy.

The tool does not promise upload permission or that a particular request will
fit. Issue read-only state, note visibility/editability, upload thresholds,
file content checks and effective limits are still enforced during the write.
Upload exclusions do not block downloading an already existing attachment that
the caller is otherwise permitted to access.

## Verification and deployment

The PHP tests execute the native ConfigsGetCommand and file-type checks from
MantisBT 2.26.4 and 2.29.0 with fixture configuration/storage boundaries. They
cover access preflight, project overrides, absent/private values, native token
semantics, size calculations and generic files on issues/new/existing notes.
Python tests prepare real ZIP, log and binary files and verify exact native
upload/download bytes and hashes. This local coverage does not establish the
values configured on a live installation.

Deploy the new plugin directory and follow the [update steps](INSTALL.md).
Discover policy for a known accessible issue before a live upload. There are
twelve read tools and eleven optional write tools. Verify a permitted original non-image attachment and exact MCP
download; only dispose of data created for the authorized test. The smoke helper
discovers and checks this read tool automatically when present, while still
checking older servers without it by their actual supported capabilities.
