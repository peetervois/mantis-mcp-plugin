<?php
/**
 * Read-only live installation check. Credentials are supplied only through env.
 * SPDX-License-Identifier: BSD-3-Clause
 * Copyright (c) 2026, Peeter Vois
 */

$url = getenv('MANTIS_MCP_URL');
$token = getenv('MANTIS_API_TOKEN');
$parts = $url ? parse_url($url) : false;
if (!$parts || !isset($parts['scheme'], $parts['host'])
    || isset($parts['user']) || isset($parts['pass']) || isset($parts['query'])
    || isset($parts['fragment'])
    || ($parts['scheme'] !== 'https'
        && !($parts['scheme'] === 'http'
            && in_array($parts['host'], array('localhost', '127.0.0.1', '[::1]'), true)))
    || !$token || preg_match('/[\r\n]/', $token)) {
    fwrite(STDERR, "Set MANTIS_MCP_URL to an HTTPS MCP endpoint and MANTIS_API_TOKEN to a personal token.\n");
    exit(1);
}

function request_mcp($url, $token, $method, $params) {
    static $id = 0;
    $headers = array('Content-Type: application/json', 'Accept: application/json, text/event-stream');
    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    $context = stream_context_create(array(
        'http' => array(
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => json_encode(array('jsonrpc' => '2.0', 'id' => ++$id,
                'method' => $method, 'params' => $params)),
            'ignore_errors' => true, 'follow_location' => 0,
            'max_redirects' => 0, 'timeout' => 20,
        ),
        'ssl' => array('verify_peer' => true, 'verify_peer_name' => true),
    ));
    $body = @file_get_contents($url, false, $context);
    if ($body === false || !isset($http_response_header[0])
        || !preg_match('~^HTTP/\S+ ([0-9]{3})~', $http_response_header[0], $match)) {
        throw new RuntimeException('The endpoint could not be reached; no credentials were printed.');
    }
    return array((int) $match[1], json_decode($body));
}

function require_rpc_success($response, $label) {
    if ($response[0] !== 200 || !$response[1] instanceof stdClass
        || isset($response[1]->error) || !isset($response[1]->result)) {
        throw new RuntimeException($label . ' failed (HTTP ' . $response[0] . ').');
    }
    return $response[1]->result;
}

try {
    foreach (array(null, 'not-a-valid-mantis-api-token') as $invalid) {
        $response = request_mcp($url, $invalid, 'tools/list', new stdClass());
        if ($response[0] !== 401) {
            throw new RuntimeException('Missing/invalid-token rejection failed (expected HTTP 401).');
        }
    }
    echo "PASS missing and invalid tokens are rejected\n";
    require_rpc_success(request_mcp($url, $token, 'initialize', (object) array(
        'protocolVersion' => '2025-06-18', 'capabilities' => new stdClass(),
        'clientInfo' => (object) array('name' => 'mantis-mcp-smoke', 'version' => '0.1.0'))),
        'Initialize');
    echo "PASS initialize\n";
    $tools = require_rpc_success(request_mcp($url, $token, 'tools/list', new stdClass()), 'Tool discovery');
    if (!isset($tools->tools) || !is_array($tools->tools)) {
        throw new RuntimeException('Tool discovery returned an unexpected result.');
    }
    echo "PASS tool discovery (" . count($tools->tools) . " tools)\n";
    $identity = require_rpc_success(request_mcp($url, $token, 'tools/call', (object) array(
        'name' => 'mantis_get_current_user', 'arguments' => new stdClass())), 'Current account');
    if (!empty($identity->isError) || !isset($identity->structuredContent->id)) {
        throw new RuntimeException('Current-account lookup failed.');
    }
    $expected = getenv('MANTIS_MCP_EXPECTED_USER_ID');
    if ($expected !== false && $expected !== ''
        && (int) $identity->structuredContent->id !== (int) $expected) {
        throw new RuntimeException('The API-token owner differs from the expected account.');
    }
    echo "PASS authenticated account id " . (int) $identity->structuredContent->id . "\n";
    $hidden = getenv('MANTIS_MCP_HIDDEN_ISSUE_ID');
    if ($hidden !== false && $hidden !== '') {
        $result = require_rpc_success(request_mcp($url, $token, 'tools/call', (object) array(
            'name' => 'mantis_get_issue', 'arguments' => (object) array('issue_id' => (int) $hidden))),
            'Inaccessible issue check');
        if (empty($result->isError)) {
            throw new RuntimeException('The issue expected to be inaccessible was returned.');
        }
        echo "PASS expected inaccessible issue is rejected\n";
    }
    echo "Read-only smoke checks passed.\n";
} catch (Throwable $error) {
    fwrite(STDERR, "FAIL " . $error->getMessage() . "\n");
    exit(1);
}
