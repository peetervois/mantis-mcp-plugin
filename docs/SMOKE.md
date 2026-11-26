# Read-only deployment checks

Run `php tests/smoke.php` from the source checkout with PHP 7.4 or later.
The helper reports the server's version as context. It does not compare that
label with a minimum or exact release. A compatible older/newer label passes
when the actual tools and read behavior satisfy the checks; a current label
does not hide missing tools or broken responses.

The helper checks HTTPS transport, response bounds and JSON-RPC correlation,
missing/invalid-token rejection, initialization, notification and ping, account
identity, exact known tool names, closed input schemas and mutation annotations.
Both the read-only inventory and the complete optional write inventory are
supported. Write definitions are inspected but never invoked. The optional
attachment-policy read tool is recognized when discovered. With issue checks
enabled, its absence is explicitly reported as skipped on older servers.

Supply an accessible issue to check its project/status context, full workflow
graph, status metadata, next-state/access intersection and visible relationship
types. Relationship type zero and custom types with null API names are valid.
Visible peer issues are read and complementary directions are verified. The
default maximum is 20 peers, adjustable up to 100. Extra peer checks are reported
as skipped. Status and modification time are read before and after: a change
fails the check, including a concurrent edit by another client.

Supply an issue that the current account must not read to check denials from
issue, workflow and relationship inspection, plus policy inspection when present.
A generic internal failure is not accepted as a permission denial. Denial
responses must not contain issue data. The helper does not search for private
issue IDs; the operator supplies known test IDs.

## One account

```bash
export MANTIS_MCP_URL=https://mantis.example.com/api/rest/plugins/MantisMcp/mcp
read -rsp 'Personal API token: ' MANTIS_API_TOKEN
printf '\n'
export MANTIS_API_TOKEN
export MANTIS_MCP_EXPECTED_USER_ID=12
export MANTIS_MCP_ISSUE_ID=123
export MANTIS_MCP_HIDDEN_ISSUE_ID=456
export MANTIS_MCP_WRITES=auto
php tests/smoke.php
unset MANTIS_API_TOKEN
```

Replace the example IDs with known test data. `MANTIS_MCP_WRITES` accepts `auto`
(all seven writes or none), `required`, or `disabled`. It controls inventory
expectations, not whether any mutation is attempted. `MANTIS_MCP_MAX_PEERS`
sets the reverse-direction check limit. Credentials are read only from
environment variables, never URL parameters or command-line arguments. Only
HTTPS and loopback HTTP URLs without credentials, query or fragment are accepted;
redirects are disabled and TLS verification remains enabled.

## Separate account

Keep the primary token set, then supply the second account independently:

```bash
read -rsp 'Second personal API token: ' MANTIS_API_TOKEN_SECOND
printf '\n'
export MANTIS_API_TOKEN_SECOND
export MANTIS_MCP_SECOND_EXPECTED_USER_ID=13
export MANTIS_MCP_SECOND_ISSUE_ID=456
export MANTIS_MCP_SECOND_HIDDEN_ISSUE_ID=123
php tests/smoke.php
unset MANTIS_API_TOKEN MANTIS_API_TOKEN_SECOND
```

The second account has its own issue/denial/inventory checks, using the
`MANTIS_MCP_SECOND_` prefix. Account IDs must differ, and the primary identity is
read again after the second account to detect authentication-state leakage.

## Scope of evidence

Unspecified accessible issue, inaccessible issue and second account groups are
explicitly reported as `SKIP`, including a final count. Do not treat a basic run
without those inputs as complete permission or workflow acceptance.

The local PHP suite exercises these assertions against simulated responses
transported through the real Slim/MCP authentication harness, including broken
inventories, stale/malformed workflow data, wrong reverse links and failed
permission checks. That is separate from an authenticated live CLI run. Tests
can also validate selected live MCP read captures with the same pure assertions;
captures do not verify the CLI's HTTP/authentication exchange. Report which
route was actually used. No test writes, production credentials or private
captures belong in the public source repository.
