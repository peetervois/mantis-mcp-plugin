# Workflow inspection

Failed `mantis_update_issue` calls that requested `status_id` retain their
original sanitized error and add a short workflow hint in both text and
`structuredContent.workflow_hint`. The hint names two read tools with the
caller-supplied issue id: first `mantis_get_issue`, then `mantis_get_workflow`.
It adds no actual issue state, performs no extra reads, and never retries a
write. The status change is unconfirmed: a later error may follow a partial
write, so readback must precede any retry. The hint does not assert that workflow
was the cause; permission, read-only/reopen rules, required fields and native
validation still apply even if the graph offers a candidate transition.

`mantis_get_workflow(issue_id)` is a read-only operation for an accessible issue.
It returns the issue/project ids, current status, effective status names/labels,
native status thresholds, a graph of allowed transition ids, and the intersection
of current-state workflow edges with the caller's status access.

The `transitions` rows use `from_status_id` and `to_status_ids`. For example, if
Working can reach Resolved and Resolved can reach Testing, the client can discover
that the direct Working-to-Testing step is absent instead of trying it blindly.
Do not advance an issue through an intermediate state unless the work itself
justifies that state and the user's request authorizes the change.

`workflow_and_status_access_next_ids` checks workflow and status thresholds only.
`is_readonly` and `current_user_can_update_issue_fields` provide additional
context. The result does not promise a status update will succeed: native
reopen/read-only behavior, required fields, project rules and other validation
still apply. Actual writes continue through `mantis_update_issue` and native
MantisBT validation.

The tool invokes native bug_check_workflow and access_get_status_threshold with
the issue's project context and restores the previous context afterward. Native
fallback semantics apply if a workflow is empty or lacks a row. Same-status
no-ops are omitted from graph edges. At most 200 configured statuses are accepted
to bound graph construction. No arbitrary configuration key is exposed.

The fixture integration tests load the exact supported upstream workflow/helper
bodies and MantisEnum class against fixture configuration and access data. Live
acceptance should compare a project's graph with its workflow page and verify
that inspection does not modify issue status.
