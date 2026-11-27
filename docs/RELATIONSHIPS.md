# Issue relationships

The authenticated read tools `mantis_get_relationship_types` and
`mantis_get_relationships(issue_id)` expose native relationship definitions and
visible links. Types include custom MantisBT definitions and their complementary
type ids. A type is always relative to the issue being read, so the other end
of a parent/child or duplicate link reports the native reverse type.

With the existing write-tool setting enabled:

- `mantis_set_relationship(issue_id, target_issue_id, type_id)` creates a link
  or changes the existing link between the pair to that type.
- `mantis_delete_relationship(issue_id, relationship_id)` deletes a selected
  link. This removes the relationship, not either issue.

Discover type ids and read existing links before replacing a type or deleting a
link. Both issues must be accessible. Native source-issue update permission,
read-only checks and target visibility apply. A relationship on another issue
or involving an inaccessible peer cannot be deleted by guessing its id. Hidden
peers and their counts are omitted from list results. Type id zero is valid;
issue and relationship ids must be positive integers.

Mutations invoke native IssueRelationshipAddCommand and
IssueRelationshipDeleteCommand in process and verify their effects. All calls
use the existing personal token; there are no arbitrary REST/SQL/config inputs.
Native history and notification behavior remain in use.

Since version 0.4.1, the adapter clears both endpoint relationship caches after native mutations
before verifying their effects. This is needed on MantisBT 2.29, where a
preflight read can otherwise leave a successfully deleted link in the current
request's cached list and cause a false failure response.

For live acceptance, use two permitted disposable test issues. Discover types,
create a link, verify both directions, change its type, then delete that test
link and verify it disappeared. Also check an account without access to the
other issue. Local tests cover native command validation and direction with a
fixture relationship storage boundary; they do not replace deployment testing.
