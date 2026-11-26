<?php
/** SPDX-License-Identifier: BSD-3-Clause */
require __DIR__ . '/smoke.php';
use Tauria\MantisMcp\Smoke\Checks;

function smoke_fixture(?callable $mutate = null, array $options = array()) {
    $calls = array(); $lines = array();
    $request = function($role, $method, $params) use ($mutate, &$calls) {
        $tokens = array('primary' => 'alice-key', 'secondary' => 'bob-key', 'missing' => null, 'invalid' => 'bad-key');
        $calls[] = array($role, $method, isset($params->name) ? $params->name : null);
        $message = rpc($method, $params);
        if (strpos($method, 'notifications/') === 0) { unset($message->id); }
        $response = send_request($message, $tokens[$role]);
        $result = array($response->getStatusCode(), decoded($response));
        return $mutate ? $mutate($result, $role, $method, $params) : $result;
    };
    (new Checks($request, function($line) use (&$lines) { $lines[] = $line; }))->run($options);
    return array($calls, $lines);
}

function smoke_fails(callable $run) {
    try { $run(); } catch (RuntimeException $e) { return; }
    throw new RuntimeException('Expected smoke check to reject simulated response');
}

test_case('smoke accepts older version labels when actual capabilities and read behavior pass', function() {
    $GLOBALS['test_mantis']['writes'] = true;
    relationship_upsert(94, 96, 2);
    list($calls, $lines) = smoke_fixture(function($r, $role, $method) {
        if ($method === 'initialize' && $r[0] === 200) { $r[1]->result->serverInfo->version = '0.1.0'; }
        return $r;
    }, array('issue_id' => 94, 'hidden_issue_id' => 95, 'expected_user_id' => 1, 'writes' => 'required'));
    check(strpos(implode("\n", $lines), 'version 0.1.0') !== false);
    foreach ($calls as $call) {
        if ($call[1] === 'tools/call') { check(in_array($call[2], array_merge(Checks::READS, array('mantis_get_attachment_policy')), true)); }
    }
    foreach ($GLOBALS['test_mantis']['calls'] as $call) { same('GET', $call['method']); }
});

test_case('smoke checks separate account visibility and rechecks the first identity', function() {
    list($calls) = smoke_fixture(null, array('issue_id' => 94, 'hidden_issue_id' => 95,
        'expected_user_id' => 1, 'secondary' => true,
        'secondary_options' => array('issue_id' => 95, 'hidden_issue_id' => 94, 'expected_user_id' => 2)));
    same(array('primary', 'tools/call', 'mantis_get_current_user'), end($calls));
});

test_case('smoke reports omitted optional groups instead of claiming they were tested', function() {
    list(, $lines) = smoke_fixture();
    same(3, count(array_filter($lines, function($s) { return strpos($s, 'SKIP ') === 0; })));
});

test_case('smoke checks the older core inventory deeply without requiring the optional policy tool', function() {
    list($calls, $lines) = smoke_fixture(function($r, $role, $method) {
        if ($method === 'tools/list' && $r[0] === 200) {
            $r[1]->result->tools = array_values(array_filter($r[1]->result->tools, function($t) {
                return !in_array($t->name, Checks::OPTIONAL_READS, true);
            }));
        }
        return $r;
    }, array('issue_id' => 94));
    check(strpos(implode("\n", $lines), 'attachment-policy check: tool not advertised') !== false);
    foreach ($calls as $call) { check($call[2] !== 'mantis_get_attachment_policy'); }
});

test_case('smoke rejects a renamed tool even when the advertised tool count is unchanged', function() {
    smoke_fails(function() { smoke_fixture(function($r, $role, $method) {
        if ($method === 'tools/list' && $r[0] === 200) { $r[1]->result->tools[6]->name = 'mantis_unknown_tool'; }
        return $r;
    }); });
});

test_case('smoke rejects duplicate definitions, bad schemas and incorrect write annotations', function() {
    foreach (array('duplicate', 'schema', 'annotation', 'partial-write', 'partial-session', 'missing-session-status', 'unsafe-retry') as $case) {
        $GLOBALS['test_mantis']['writes'] = true;
        smoke_fails(function() use ($case) { smoke_fixture(function($r, $role, $method) use ($case) {
            if ($method === 'tools/list' && $r[0] === 200) {
                $tools = &$r[1]->result->tools;
                if ($case === 'duplicate') { $tools[] = $tools[0]; }
                if ($case === 'schema') { $tools[6]->inputSchema->properties->issue_id->minimum = 0; }
                if ($case === 'annotation') { $tools[count($tools) - 1]->annotations->readOnlyHint = true; }
                if ($case === 'partial-write') { array_pop($tools); }
                if ($case === 'partial-session' || $case === 'missing-session-status') {
                    $missing = $case === 'partial-session' ? 'mantis_finish_attachment_upload' : 'mantis_get_attachment_upload';
                    $tools = array_values(array_filter($tools, function($t) use ($missing) { return $t->name !== $missing; }));
                }
                if ($case === 'unsafe-retry') {
                    foreach ($tools as $t) { if ($t->name === 'mantis_begin_attachment_upload') { $t->annotations->idempotentHint = true; } }
                }
            }
            return $r;
        }); });
    }
});

test_case('smoke requires the configured read-only or write-enabled inventory', function() {
    smoke_fails(function() { smoke_fixture(null, array('writes' => 'required')); });
    $GLOBALS['test_mantis']['writes'] = true;
    smoke_fails(function() { smoke_fixture(null, array('writes' => 'disabled')); });
});

test_case('smoke detects missing new-note and existing-note file capabilities', function() {
    $GLOBALS['test_mantis']['writes'] = true;
    foreach (array('mantis_add_note', 'mantis_upload_attachment') as $name) {
        smoke_fails(function() use ($name) { smoke_fixture(function($r, $role, $method) use ($name) {
            if ($method === 'tools/list' && $r[0] === 200) {
                foreach ($r[1]->result->tools as $t) {
                    if ($t->name === $name) {
                        if ($name === 'mantis_add_note') { unset($t->inputSchema->properties->attachment); }
                        else { unset($t->inputSchema->properties->note_id); }
                    }
                }
            }
            return $r;
        }); });
    }
});

test_case('smoke rejects malformed workflow context, edges and next-state intersections', function() {
    foreach (array('context', 'target', 'intersection', 'missing-row') as $case) {
        smoke_fails(function() use ($case) { smoke_fixture(function($r, $role, $method, $params) use ($case) {
            if ($method === 'tools/call' && $params->name === 'mantis_get_workflow') {
                $w = $r[1]->result->structuredContent;
                if ($case === 'context') { $w->project_id = 999; }
                if ($case === 'target') { $w->transitions[0]->to_status_ids[] = 999; }
                if ($case === 'intersection') { $w->workflow_and_status_access_next_ids[] = 30; }
                if ($case === 'missing-row') { array_pop($w->transitions); }
            }
            return $r;
        }, array('issue_id' => 94)); });
    }
});

test_case('smoke accepts custom relationship types with null API names', function() {
    smoke_fixture(function($r, $role, $method, $params) {
        if ($method === 'tools/call' && $params->name === 'mantis_get_relationship_types') {
            $r[1]->result->structuredContent->types[] = (object) array('id' => 99, 'name' => null,
                'label' => 'custom type', 'complementary_type_id' => 99);
        }
        return $r;
    });
});

test_case('smoke rejects broken complementary definitions and wrong reverse directions', function() {
    relationship_upsert(94, 96, 2);
    foreach (array('complement', 'reverse') as $case) {
        smoke_fails(function() use ($case) { smoke_fixture(function($r, $role, $method, $params) use ($case) {
            if ($method === 'tools/call' && $params->name === 'mantis_get_relationship_types' && $case === 'complement') {
                $r[1]->result->structuredContent->types[0]->complementary_type_id = 999;
            }
            if ($method === 'tools/call' && $params->name === 'mantis_get_relationships'
                && $params->arguments->issue_id === 96 && $case === 'reverse') {
                $r[1]->result->structuredContent->relationships[0]->type->id = 1;
            }
            return $r;
        }, array('issue_id' => 94)); });
    }
});

test_case('smoke does not confuse an internal tool failure or leaked issue data with permission denial', function() {
    foreach (array('internal-error', 'issue-data', 'error-code', 'block-metadata') as $case) {
        smoke_fails(function() use ($case) { smoke_fixture(function($r, $role, $method, $params) use ($case) {
            if ($method === 'tools/call' && $params->name === 'mantis_get_issue' && $params->arguments->issue_id === 95) {
                if ($case === 'internal-error') { $r[1]->result->content[0]->text = 'Internal failure'; }
                elseif ($case === 'error-code') { $r[1]->result->structuredContent = (object) array('error_code' => 'private fixture'); }
                elseif ($case === 'block-metadata') { $r[1]->result->content[0]->_meta = (object) array('detail' => 'private fixture'); }
                else { $r[1]->result->structuredContent = (object) array('issues' => array((object) array('id' => 95))); }
            }
            return $r;
        }, array('hidden_issue_id' => 95)); });
    }
});

test_case('smoke detects an issue changing during read-only checks', function() {
    $reads = 0;
    smoke_fails(function() use (&$reads) { smoke_fixture(function($r, $role, $method, $params) use (&$reads) {
        if ($method === 'tools/call' && $params->name === 'mantis_get_issue' && $params->arguments->issue_id === 94) {
            if (++$reads > 1) { $r[1]->result->structuredContent->issues[0]->updated_at = '2026-01-02T00:00:00Z'; }
        }
        return $r;
    }, array('issue_id' => 94)); });
});

test_case('smoke rejects invalid environment ids before making requests', function() {
    $key = 'MANTIS_SMOKE_TEST_ISSUE_ID';
    try {
        foreach (array('0', '-1', '1junk', '1.5', '9999999999999999999999999999') as $value) {
            putenv($key . '=' . $value);
            smoke_fails(function() { smoke_options('MANTIS_SMOKE_TEST_'); });
        }
    } finally { putenv($key); }
});
