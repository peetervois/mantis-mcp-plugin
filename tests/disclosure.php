<?php
/** SPDX-License-Identifier: BSD-3-Clause */

function assert_empty_denial($response, $status) {
    $reasons = array(401 => 'Unauthorized', 403 => 'Forbidden', 429 => 'Too Many Requests',
        500 => 'Internal Server Error', 503 => 'Service Unavailable');
    same($status, $response->getStatusCode(), 'Unexpected denial status');
    same('', (string) $response->getBody(), 'Denial body must be empty');
    same($reasons[$status], $response->getReasonPhrase(), 'Unexpected denial reason');
    $expected = array('cache-control' => array('no-store, private'), 'vary' => array('Authorization, Origin'));
    if ($status === 401) { $expected['www-authenticate'] = array('Bearer'); }
    $headers = array_change_key_case($response->getHeaders(), CASE_LOWER);
    if (isset($headers['content-length'])) {
        same(array('0'), $headers['content-length']);
        unset($headers['content-length']);
    }
    ksort($expected);
    ksort($headers);
    same($expected, $headers, 'Unexpected denial headers');
}

function disclosure_initialize_request() {
    return rpc('initialize', (object) array('protocolVersion' => '2025-06-18',
        'capabilities' => new stdClass(), 'clientInfo' => (object) array('name' => 'fixture', 'version' => '1')));
}

test_case('discovery, tool calls and request validation share an empty response without an accepted key', function() {
    $initialize = disclosure_initialize_request();
    $messages = array($initialize, rpc('tools/list'), rpc('resources/list'), rpc('prompts/list'), rpc('ping'),
        tool_call('mantis_get_current_user'),
        tool_call('mantis_create_issue', (object) array('project_id' => 1, 'category_id' => 5,
            'summary' => 'Fixture', 'description' => 'Fixture')), '{');
    unset($GLOBALS['test_mantis']['tokens']['bob-key']);
    foreach (array(false, true) as $writes) {
        $GLOBALS['test_mantis']['writes'] = $writes;
        foreach (array(null, 'unknown-fixture-key', 'bob-key') as $token) {
            foreach ($messages as $message) {
                assert_empty_denial(send_request($message, $token), 401);
            }
        }
    }
    foreach (array('GET', 'HEAD', 'OPTIONS') as $method) {
        assert_empty_denial(send_request('', null, array('method' => $method)), 401);
    }
    assert_empty_denial(send_request(rpc('tools/list'), null, array('headers' =>
        array('Authorization' => array('Bearer alice-key', 'Bearer bob-key')))), 401);
    foreach (array('/mantis/api/rest/plugins/MantisMcp/mcp/',
        '/mantis/api/rest/index.php/plugins/MantisMcp/mcp') as $path) {
        assert_empty_denial(send_request($initialize, null, array('path' => $path,
            'script_name' => '/mantis/api/rest/index.php')), 401);
    }
    same(array(), $GLOBALS['test_mantis']['calls']);
    check(isset(decoded(send_request($initialize))->result->capabilities->tools));
    check(count(decoded(send_request(rpc('tools/list')))->result->tools) > 0);
});

test_case('working browser and guest sessions authorize ordinary REST only', function() {
    $GLOBALS['test_mantis']['anonymous'] = true;
    foreach (array(0 => 'guest', 1 => 'alice', 4 => 'guest') as $session => $name) {
        $normal = send_request('', null, array('method' => 'GET', 'path' => '/unrelated', 'cookie_user' => $session));
        same(200, $normal->getStatusCode());
        same($name, $normal->getHeaderLine(HEADER_USERNAME));
        same($session === 0 ? LOGIN_METHOD_ANONYMOUS : LOGIN_METHOD_COOKIE, $normal->getHeaderLine(HEADER_LOGIN_METHOD));
        assert_empty_denial(send_request(rpc('tools/list'), null, array('cookie_user' => $session)), 401);
    }
});

test_case('guest web access cannot replace a missing or invalid MCP token', function() {
    $initialize = disclosure_initialize_request();
    $discovery = rpc('tools/list');
    $identity = tool_call('mantis_get_current_user');
    foreach (array(false, true) as $allowAnonymous) {
        $GLOBALS['test_mantis']['anonymous'] = $allowAnonymous;
        foreach (array(array(), array('cookie_user' => 4)) as $browser) {
            foreach (array(null, 'unknown-fixture-key') as $token) {
                assert_empty_denial(send_request($initialize, $token, $browser), 401);
                assert_empty_denial(send_request($discovery, $token, $browser), 401);
                assert_empty_denial(send_request($identity, $token, $browser), 401);
            }
        }
    }
    same(array(), $GLOBALS['test_mantis']['calls'], 'Denied requests must not dispatch a REST tool');
});

test_case('guest and personal tokens select their owners independently of the browser account', function() {
    $state = &$GLOBALS['test_mantis'];
    $state['anonymous'] = true;
    $state['tokens']['fixture-guest-key'] = 4;
    $identity = tool_call('mantis_get_current_user');
    $cases = array(
        array('fixture-guest-key', array(), 4, 'guest'),
        array('alice-key', array('cookie_user' => 4), 1, 'alice'),
        array('fixture-guest-key', array('cookie_user' => 1), 4, 'guest'),
    );
    foreach ($cases as list($token, $browser, $id, $name)) {
        $response = send_request($identity, $token, $browser);
        same(200, $response->getStatusCode());
        $result = decoded($response)->result;
        same(false, $result->isError);
        same($id, $result->structuredContent->id);
        same($name, $result->structuredContent->name);
    }
    same(array(4, 1, 4), array_column($state['calls'], 'user'));
});

test_case('guest sessions honor REST access changes and token revocation on the next request', function() {
    $state = &$GLOBALS['test_mantis'];
    $state['anonymous'] = true;
    $token = 'fixture-guest-key';
    $state['tokens'][$token] = 4;
    $browser = array('cookie_user' => 4);
    $identity = tool_call('mantis_get_current_user');
    $discovery = rpc('tools/list');

    same(4, decoded(send_request($identity, $token, $browser))->result->structuredContent->id);
    $state['api_access'] = false;
    assert_empty_denial(send_request($discovery, $token, $browser), 401);
    assert_empty_denial(send_request($identity, $token, $browser), 401);

    $state['api_access'] = true;
    same(4, decoded(send_request($identity, $token, $browser))->result->structuredContent->id);
    unset($state['tokens'][$token]);
    assert_empty_denial(send_request($discovery, $token, $browser), 401);
    assert_empty_denial(send_request($identity, $token, $browser), 401);
    same(array(4, 4), array_column($state['calls'], 'user'));
});

test_case('unusable accounts and identity mismatches do not disclose account or API access state', function() {
    assert_empty_denial(send_request(rpc('tools/list'), 'disabled-key'), 401);
    $GLOBALS['test_mantis']['api_access'] = false;
    assert_empty_denial(send_request(rpc('tools/list')), 401);
    $GLOBALS['test_mantis']['api_access'] = true;
    $GLOBALS['test_mantis']['forced_user'] = 2;
    assert_empty_denial(send_request(tool_call('mantis_get_current_user')), 401);
    same(array(), $GLOBALS['test_mantis']['calls']);
});

test_case('origin and impersonation policies reject requests without describing the policy', function() {
    foreach (array(null, 'unknown-fixture-key', 'alice-key') as $key) {
        foreach (array(array('Origin' => 'https://other.invalid'), array('X-Mantis-Username' => 'bob'),
            array('Mantis-Username' => 'bob')) as $headers) {
            assert_empty_denial(send_request(rpc('tools/list'), $key, array('headers' => $headers)), 403);
        }
    }
    same(array(), $GLOBALS['test_mantis']['calls']);
});

test_case('native REST availability and exceptions cannot put diagnostics in MCP denials', function() {
    $GLOBALS['test_mantis']['offline'] = true;
    assert_empty_denial(send_request(rpc('ping'), null), 401);
    assert_empty_denial(send_request(rpc('ping')), 503);
    $GLOBALS['test_mantis']['offline'] = false;
    $GLOBALS['test_mantis']['config']['webservice_rest_enabled'] = OFF;
    assert_empty_denial(send_request(rpc('ping')), 503);
    foreach (array(200 => 401, 302 => 401, 401 => 401, 403 => 401, 404 => 401, 429 => 429, 500 => 500) as $status => $expected) {
        $middleware = function($request, $response) use ($status) {
            echo 'printed private fixture diagnostic';
            return $response->withJson(array('diagnostic' => 'private fixture detail'))
                ->withStatus($status, 'Private fixture reason')->withHeader('Set-Cookie', 'fixture=value')
                ->withHeader('X-Fixture-Diagnostic', 'private fixture detail');
        };
        assert_empty_denial(send_request(rpc('ping'), 'alice-key', array('middleware' => $middleware)), $expected);
    }
    $throws = function($request, $response) {
        echo 'printed private fixture exception context';
        $response->getBody()->write('partial private fixture output');
        throw new RuntimeException('private fixture exception');
    };
    assert_empty_denial(send_request(rpc('ping'), 'alice-key', array('middleware' => $throws)), 500);
    same(array(), $GLOBALS['test_mantis']['calls']);
});

test_case('authenticated MCP responses omit native identity and version headers', function() {
    $responses = array(send_request(rpc('tools/list')), send_request(rpc('ping'), 'alice-key', array('method' => 'GET')),
        send_request(tool_call('mantis_get_issue', (object) array('issue_id' => 95))));
    foreach ($responses as $response) {
        foreach (array(HEADER_USERNAME, HEADER_LOGIN_METHOD, HEADER_VERSION, 'Deprecation', 'Link') as $header) {
            check(!$response->hasHeader($header), 'Unexpected native header ' . $header);
        }
    }
    same(200, $responses[0]->getStatusCode());
    same('POST', $responses[1]->getHeaderLine('Allow'));
    same(true, decoded($responses[2])->result->isError);
});

test_case('forbidden and missing native resources produce identical data-free tool results', function() {
    foreach (array('mantis_get_issue', 'mantis_get_workflow', 'mantis_get_relationships',
        'mantis_get_attachment_policy') as $tool) {
        $message = tool_call($tool, (object) array('issue_id' => 95));
        $GLOBALS['test_mantis']['issue_denial_status'] = 403;
        $forbidden = send_request($message);
        $GLOBALS['test_mantis']['issue_denial_status'] = 404;
        $missing = send_request($message);
        same((string) $forbidden->getBody(), (string) $missing->getBody());
        $result = decoded($forbidden)->result;
        same(true, $result->isError);
        same(array('content', 'isError'), array_keys((array) $result));
        same(1, count($result->content));
        same('The item does not exist or is not accessible to your MantisBT account.', $result->content[0]->text);
    }
    same(array('issues'), array_values(array_unique(array_column($GLOBALS['test_mantis']['calls'], 'name'))));
});

test_case('private note attachment denials hide the file and never read its bytes', function() {
    $private = download_call(array('issue_id' => 96, 'attachment_id' => 220), 'bob-key');
    $absent = download_call(array('issue_id' => 96, 'attachment_id' => 999), 'bob-key');
    same((string) $private->getBody(), (string) $absent->getBody());
    $result = decoded($private)->result;
    same(true, $result->isError);
    same('The attachment does not exist or is not accessible to your MantisBT account.', $result->content[0]->text);
    same(array('content', 'isError'), array_keys((array) $result));
    same(0, file_reads());
    same(false, decoded(download_call(array('issue_id' => 96, 'attachment_id' => 221), 'bob-key'))->result->isError);
});

test_case('native access exceptions and HTTP denials have the same public result', function() {
    $message = tool_call('mantis_get_issue', (object) array('issue_id' => 95));
    $expected = (string) send_request($message)->getBody();
    $detail = 'private fixture detail';
    foreach (array(new \Mantis\Exceptions\LegacyApiFaultException($detail, 403),
        new \Mantis\Exceptions\LegacyApiFaultException($detail, 404),
        new \Mantis\Exceptions\ClientException($detail, ERROR_ACCESS_DENIED),
        new \Mantis\Exceptions\ClientException($detail, ERROR_BUG_NOT_FOUND)) as $fault) {
        $GLOBALS['test_mantis']['issue_fault'] = $fault;
        same($expected, (string) send_request($message)->getBody());
    }
});

test_case('foreign upload sessions and missing sessions return the same result regardless of lock state', function() {
    $GLOBALS['test_mantis']['writes'] = true;
    $absent = str_repeat('0', 64);
    $expected = session_call('get', $absent, 'bob-key')->result;
    same(true, $expected->isError);
    same('Upload session is unavailable.', $expected->content[0]->text);
    $id = session_begin('fixture file')->result->structuredContent->upload_id;
    same(json_encode($expected), json_encode(session_call('get', $absent, 'bob-key')->result));
    same(json_encode($expected), json_encode(session_call('get', $id, 'bob-key')->result));
    $lock = fopen(session_root() . '/' . $id . '.lock', 'r+b');
    try {
        check(flock($lock, LOCK_EX | LOCK_NB));
        same(json_encode($expected), json_encode(session_call('get', $id, 'bob-key')->result));
        same(json_encode($expected), json_encode(session_call('cancel', $id, 'bob-key')->result));
    } finally { flock($lock, LOCK_UN); fclose($lock); }
    same('uploading', session_call('get', $id)->result->structuredContent->state);
});
