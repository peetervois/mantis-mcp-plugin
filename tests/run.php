<?php
/**
 * SPDX-License-Identifier: BSD-3-Clause
 * Copyright (c) 2026, Peeter Vois
 */

require __DIR__ . '/bootstrap.php';

$passed = 0;
$failed = 0;
function test_case($name, callable $test) {
    global $passed, $failed;
    reset_state();
    try {
        $test();
        ++$passed;
        echo "PASS " . $name . "\n";
    } catch (Throwable $error) {
        ++$failed;
        fwrite(STDERR, "FAIL " . $name . ': ' . $error->getMessage() . "\n");
    }
}

test_case('registers a native MantisBT plugin and REST hook', function() {
    $plugin = new MantisMcpPlugin('MantisMcp');
    same('0.3.0', $plugin->version);
    same('2.26.4', $plugin->requires['MantisCore']);
    same('routes', $plugin->hooks()['EVENT_REST_API_ROUTES']);
    same(OFF, $plugin->config()['enable_write_tools']);
});

test_case('negotiates initialize with a real HTTP JSON response', function() {
    $response = send_request(rpc('initialize', (object) array(
        'protocolVersion' => '2025-06-18', 'capabilities' => new stdClass(),
        'clientInfo' => (object) array('name' => 'test', 'version' => '1'))));
    same(200, $response->getStatusCode());
    same('2025-06-18', decoded($response)->result->protocolVersion);
    same('mantis-mcp-plugin', decoded($response)->result->serverInfo->name);
    check(strpos($response->getHeaderLine('Cache-Control'), 'no-store') !== false);
    same('', $response->getHeaderLine('Mcp-Session-Id'));
});

test_case('negotiates unsupported proposed versions', function() {
    $response = send_request(rpc('initialize', (object) array(
        'protocolVersion' => '2099-01-01', 'capabilities' => new stdClass(),
        'clientInfo' => (object) array('name' => 'test', 'version' => '1'))));
    same('2025-06-18', decoded($response)->result->protocolVersion);
});

test_case('rejects missing and malformed initialize params', function() {
    same(-32602, decoded(send_request(rpc('initialize')))->error->code);
    $message = rpc('ping');
    $message->params = null;
    same(-32602, decoded(send_request($message))->error->code);
});

test_case('lists valid read-only schemas and never changes validation after discovery', function() {
    $response = decoded(send_request(rpc('tools/list')));
    same(9, count($response->result->tools));
    foreach ($response->result->tools as $tool) {
        same('object', $tool->inputSchema->type);
        check($tool->inputSchema->properties instanceof stdClass);
        same(true, $tool->annotations->readOnlyHint);
    }
    $tools = new \Tauria\MantisMcp\Tools();
    $tools->definitions();
    $tools->validate('mantis_get_issue', (object) array('issue_id' => 94, 'select' => 'id,summary'));
});

test_case('requires tokens even when a browser account is authenticated', function() {
    $response = send_request(rpc('tools/list'), null, array('cookie_user' => 1));
    same(401, $response->getStatusCode());
    check(strpos($response->getHeaderLine('WWW-Authenticate'), 'Bearer') === 0);
    same(array(), $GLOBALS['test_mantis']['calls']);
});

test_case('rejects unknown and revoked tokens', function() {
    same(401, send_request(rpc('tools/list'), 'invalid-key')->getStatusCode());
    same(200, send_request(rpc('tools/list'))->getStatusCode());
    unset($GLOBALS['test_mantis']['tokens']['alice-key']);
    same(401, send_request(rpc('tools/list'))->getStatusCode());
});

test_case('rejects disabled accounts through MantisBT AuthMiddleware', function() {
    same(403, send_request(rpc('tools/list'), 'disabled-key')->getStatusCode());
});

test_case('accepts legacy bare API tokens and case-insensitive Bearer schemes', function() {
    foreach (array('alice-key', 'bEaReR alice-key') as $header) {
        $response = send_request(tool_call('mantis_get_current_user'), null,
            array('headers' => array('Authorization' => $header)));
        same(1, decoded($response)->result->structuredContent->id);
    }
});

test_case('rejects multiple credentials and query-string credentials', function() {
    foreach (array('Bearer alice-key, Bearer bob-key', 'Bearer alice-key bob-key',
        array('Bearer alice-key', 'Bearer bob-key')) as $header) {
        same(401, send_request(rpc('tools/list'), null,
            array('headers' => array('Authorization' => $header)))->getStatusCode());
    }
    same(401, send_request(rpc('tools/list'), null,
        array('path' => '/plugins/MantisMcp/mcp?token=alice-key'))->getStatusCode());
});

test_case('binds successive calls to each token owner without shared state', function() {
    foreach (array(array('alice-key', 1), array('bob-key', 2), array('alice-key', 1)) as $account) {
        same($account[1], decoded(send_request(tool_call('mantis_get_current_user'), $account[0]))
            ->result->structuredContent->id);
    }
    $body = (string) send_request(tool_call('mantis_get_current_user'))->getBody();
    check(strpos($body, 'alice-key') === false);
});

test_case('does not return another account private issue', function() {
    $call = tool_call('mantis_get_issue', (object) array('issue_id' => 94));
    same(false, decoded(send_request($call, 'alice-key'))->result->isError);
    same(true, decoded(send_request($call, 'bob-key'))->result->isError);
    same(false, decoded(send_request($call, 'alice-key'))->result->isError);
});

test_case('rejects impersonation headers', function() {
    foreach (array('X-Mantis-Username', 'Mantis-Username') as $header) {
        same(403, send_request(rpc('tools/list'), 'alice-key',
            array('headers' => array($header => 'bob')))->getStatusCode());
    }
});

test_case('rejects mismatched token owner and authenticated account', function() {
    $GLOBALS['test_mantis']['forced_user'] = 2;
    same(401, send_request(tool_call('mantis_get_current_user'))->getStatusCode());
});

test_case('validates Origin against the configured MantisBT URL', function() {
    foreach (array('https://attacker.example', 'null', '', 'https://central.tauria.ee/path',
        'http://central.tauria.ee') as $origin) {
        same(403, send_request(rpc('tools/list'), 'alice-key',
            array('headers' => array('Origin' => $origin)))->getStatusCode());
    }
    foreach (array('https://central.tauria.ee', 'https://central.tauria.ee:443') as $origin) {
        same(200, send_request(rpc('tools/list'), 'alice-key',
            array('headers' => array('Origin' => $origin)))->getStatusCode());
    }
});

test_case('limits guard changes to its own endpoint', function() {
    // Existing REST endpoints still expect the normal bare token.
    $response = send_request('', null, array('path' => '/unrelated', 'method' => 'GET',
        'headers' => array('Authorization' => 'alice-key')));
    same(200, $response->getStatusCode());
    same(true, decoded($response)->unrelated);
});

test_case('rejects unsupported HTTP methods and MIME types', function() {
    foreach (array('GET', 'DELETE', 'PUT', 'PATCH', 'OPTIONS') as $method) {
        $response = send_request('', 'alice-key', array('method' => $method));
        same(405, $response->getStatusCode());
        same('POST', $response->getHeaderLine('Allow'));
    }
    same(415, send_request('{}', 'alice-key', array('type' => 'text/plain'))->getStatusCode());
});

test_case('supports the trailing-slash endpoint', function() {
    same(200, send_request(rpc('ping'), 'alice-key',
        array('path' => '/plugins/MantisMcp/mcp/'))->getStatusCode());
});

test_case('supports Apache subdirectories with and without URL rewriting', function() {
    foreach (array('/mantis/api/rest/plugins/MantisMcp/mcp',
        '/mantis/api/rest/index.php/plugins/MantisMcp/mcp') as $path) {
        $options = array('path' => $path, 'script_name' => '/mantis/api/rest/index.php');
        same(401, send_request(rpc('ping'), null, $options)->getStatusCode());
        $response = send_request(tool_call('mantis_get_current_user'), 'alice-key', $options);
        same(200, $response->getStatusCode());
        same(1, decoded($response)->result->structuredContent->id);
    }
});

test_case('rejects oversized requests', function() {
    $GLOBALS['test_mantis']['max_bytes'] = 1024;
    same(413, send_request(str_repeat('x', 1025))->getStatusCode());
});

test_case('rejects malformed JSON, batches and invalid requests', function() {
    same(-32700, decoded(send_request('{'))->error->code);
    foreach (array('[]', 'null', '{"method":"ping","id":1}', '{"jsonrpc":"2.0","method":"ping","id":null}')
        as $body) {
        $response = send_request($body);
        same(400, $response->getStatusCode());
        same(-32600, decoded($response)->error->code);
    }
});

test_case('preserves zero and string JSON-RPC request ids', function() {
    same(0, decoded(send_request(rpc('ping', null, 0)))->id);
    same('request-1', decoded(send_request(rpc('ping', null, 'request-1')))->id);
});

test_case('returns 202 for notifications and never executes notification writes', function() {
    $GLOBALS['test_mantis']['writes'] = true;
    foreach (array('notifications/initialized', 'tools/call', 'unrecognized/notification') as $method) {
        $message = rpc($method, (object) array('name' => 'mantis_create_issue'));
        unset($message->id);
        $response = send_request($message);
        same(202, $response->getStatusCode());
        same('', (string) $response->getBody());
    }
    same(array(), $GLOBALS['test_mantis']['calls']);
});

test_case('rejects unsupported negotiated protocol headers', function() {
    same(400, send_request(rpc('ping'), 'alice-key',
        array('headers' => array('MCP-Protocol-Version' => '2099-01-01')))->getStatusCode());
});

test_case('returns JSON-RPC errors for unknown methods and tools', function() {
    same(-32601, decoded(send_request(rpc('does/not/exist')))->error->code);
    same(-32602, decoded(send_request(tool_call('php_eval')))->error->code);
    same(-32602, decoded(send_request(tool_call('mantis_create_issue')))->error->code);
    same(array(), $GLOBALS['test_mantis']['calls']);
});

test_case('validates ids, types, pagination and extra arguments before REST dispatch', function() {
    $cases = array(
        array('mantis_get_issue', (object) array('issue_id' => 0)),
        array('mantis_get_issue', (object) array('issue_id' => '94')),
        array('mantis_get_issue', (object) array('issue_id' => 94, 'url' => 'https://attacker.example')),
        array('mantis_get_issue', (object) array('issue_id' => 94, 'select' => 'id;password')),
        array('mantis_list_issues', (object) array('page_size' => 101)),
        array('mantis_list_issues', array()),
        array('mantis_get_current_user', null),
    );
    foreach ($cases as $case) {
        $message = tool_call($case[0]);
        $message->params->arguments = $case[1];
        same(-32602, decoded(send_request($message))->error->code);
    }
    same(array(), $GLOBALS['test_mantis']['calls']);
});

test_case('cleans outer MCP query params before calling native REST handlers', function() {
    send_request(tool_call('mantis_list_issues'), 'alice-key',
        array('path' => '/plugins/MantisMcp/mcp?id=95&project_id=2'));
    $call = end($GLOBALS['test_mantis']['calls']);
    same(array('page' => 1, 'page_size' => 20, 'project_id' => 0), $call['query']);
    same(null, $call['payload']);
    same('GET', $call['method']);
});

test_case('checks project access before returning project-specific enum options', function() {
    $response = decoded(send_request(tool_call('mantis_get_issue_options',
        (object) array('project_id' => 2))));
    same(true, $response->result->isError);
    same(1, count($GLOBALS['test_mantis']['calls']));
    same('projects', $GLOBALS['test_mantis']['calls'][0]['name']);
    $response = decoded(send_request(tool_call('mantis_get_issue_options',
        (object) array('project_id' => 1))));
    same(false, $response->result->isError);
    $call = end($GLOBALS['test_mantis']['calls']);
    same('options', $call['name']);
    check(!in_array('db_password', $call['query']['option'], true));
});

test_case('adds only explicitly enabled write tools with mutation annotations', function() {
    $GLOBALS['test_mantis']['writes'] = true;
    $tools = decoded(send_request(rpc('tools/list')))->result->tools;
    same(15, count($tools));
    foreach (array_slice($tools, 9) as $tool) {
        same(false, $tool->annotations->readOnlyHint);
        same(false, $tool->annotations->idempotentHint);
    }
});

test_case('creates as the current user without accepting a spoofed reporter', function() {
    $GLOBALS['test_mantis']['writes'] = true;
    $arguments = (object) array('project_id' => 1, 'category_id' => 5,
        'summary' => 'Example', 'description' => 'Description', 'priority_id' => 30);
    $response = decoded(send_request(tool_call('mantis_create_issue', $arguments)));
    same(false, $response->result->isError);
    same(100, $response->result->structuredContent->issue->id);
    check(!isset($response->result->structuredContent->issue->description));
    $call = end($GLOBALS['test_mantis']['calls']);
    same(1, $call['user']);
    same(array('id' => 1), $call['payload']['project']);
    same(array('id' => 5), $call['payload']['category']);
    same(array('id' => 30), $call['payload']['priority']);
    check(!isset($call['payload']['reporter']));
    $arguments->reporter_id = 2;
    same(-32602, decoded(send_request(tool_call('mantis_create_issue', $arguments)))->error->code);
});

test_case('keeps native REST permission failures as MCP tool errors', function() {
    $GLOBALS['test_mantis']['writes'] = true;
    $response = send_request(tool_call('mantis_create_issue', (object) array(
        'project_id' => 2, 'category_id' => 5, 'summary' => 'Example', 'description' => 'Description')),
        'bob-key');
    same(200, $response->getStatusCode());
    same(true, decoded($response)->result->isError);
    check(strpos(decoded($response)->result->content[0]->text, 'not allowed') !== false);
});

test_case('rejects empty updates and maps permitted numeric references', function() {
    $GLOBALS['test_mantis']['writes'] = true;
    same(-32602, decoded(send_request(tool_call('mantis_update_issue',
        (object) array('issue_id' => 94, 'changes' => new stdClass()))))->error->code);
    $response = decoded(send_request(tool_call('mantis_update_issue',
        (object) array('issue_id' => 94, 'changes' => (object) array('status_id' => 50, 'handler_id' => 0)))));
    same(false, $response->result->isError);
    $call = end($GLOBALS['test_mantis']['calls']);
    same('PATCH', $call['method']);
    same(array('id' => 94), $call['path']);
    same(array('id' => 50), $call['payload']['status']);
    same(array('id' => 0), $call['payload']['handler']);
    same(2, count($call['payload']));
});

test_case('uses native note authorship and preserves unspecified note visibility', function() {
    $GLOBALS['test_mantis']['writes'] = true;
    $response = decoded(send_request(tool_call('mantis_add_note',
        (object) array('issue_id' => 95, 'text' => 'A note')), 'bob-key'));
    same(2, $response->result->structuredContent->note->reporter->id);
    $call = end($GLOBALS['test_mantis']['calls']);
    same(array('text' => 'A note'), $call['payload']);
    send_request(tool_call('mantis_add_note',
        (object) array('issue_id' => 94, 'text' => 'Private note', 'private' => true)));
    $call = end($GLOBALS['test_mantis']['calls']);
    same(array('name' => 'private'), $call['payload']['view_state']);
});

require __DIR__ . '/download.php';
require __DIR__ . '/upload.php';
require __DIR__ . '/relationships.php';

echo "\n" . $passed . " passed; " . $failed . " failed.\n";
exit($failed ? 1 : 0);
