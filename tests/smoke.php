<?php
/**
 * Read-only installation checks. Credentials are accepted only through env.
 * SPDX-License-Identifier: BSD-3-Clause
 */
require __DIR__ . '/smoke-lib.php';
use Tauria\MantisMcp\Smoke\Checks;

function smoke_options($prefix) {
    $result = array();
    foreach (array('EXPECTED_USER_ID' => 'expected_user_id', 'ISSUE_ID' => 'issue_id',
        'HIDDEN_ISSUE_ID' => 'hidden_issue_id', 'MAX_PEERS' => 'max_peers') as $env => $key) {
        $v = getenv($prefix . $env);
        if ($v !== false && $v !== '') {
            Checks::ensure(preg_match('/\A[1-9][0-9]*\z/', $v) && (string) (int) $v === $v, 'Positive integer environment option');
            $result[$key] = (int) $v;
        }
    }
    Checks::ensure(!isset($result['max_peers']) || $result['max_peers'] <= 100, 'Peer-check limit (maximum 100)');
    Checks::ensure(!isset($result['issue_id'], $result['hidden_issue_id'])
        || $result['issue_id'] !== $result['hidden_issue_id'], 'Distinct accessible/inaccessible issue ids');
    $mode = getenv($prefix . 'WRITES');
    $result['writes'] = $mode === false || $mode === '' ? 'auto' : $mode;
    Checks::ensure(in_array($result['writes'], array('auto', 'required', 'disabled'), true), 'Write inventory option');
    return $result;
}

function smoke_redact($line) {
    foreach (array('MANTIS_API_TOKEN', 'MANTIS_API_TOKEN_SECOND') as $key) {
        $token = getenv($key);
        if ($token !== false && $token !== '') { $line = str_replace($token, '[redacted]', $line); }
    }
    return $line;
}

function smoke_check_denial(array $lines, $body) {
    Checks::ensure($body === '', 'Empty denial body');
    Checks::ensure(preg_match('~^HTTP/\S+ 401(?: Unauthorized)?$~iD', trim(array_shift($lines))), 'Generic denial status');
    $headers = array();
    foreach ($lines as $line) {
        $pair = explode(':', $line, 2);
        if (count($pair) !== 2) { continue; }
        $name = strtolower(trim($pair[0]));
        Checks::ensure(!preg_match('/^(?:x-mantis-|mantis-|mcp-)/', $name)
            && !in_array($name, array('set-cookie', 'link', 'location', 'allow', 'deprecation'), true),
            'Denial headers without application metadata');
        $headers[$name][] = trim($pair[1]);
    }
    Checks::ensure(isset($headers['www-authenticate']) && $headers['www-authenticate'] === array('Bearer'), 'Bare Bearer challenge');
    $cache = isset($headers['cache-control']) ? implode(',', $headers['cache-control']) : '';
    Checks::ensure(in_array('no-store', array_map('trim', explode(',', strtolower($cache))), true), 'Non-cacheable denial');
}

function smoke_transport($url, array $credentials) {
    $id = 0;
    $versions = array();
    return function($role, $method, $params) use ($url, $credentials, &$id, &$versions) {
        $notification = strpos($method, 'notifications/') === 0;
        $message = array('jsonrpc' => '2.0', 'method' => $method, 'params' => $params);
        if (!$notification) { $message['id'] = ++$id; }
        $headers = array('Content-Type: application/json', 'Accept: application/json');
        if ($credentials[$role] !== null) { $headers[] = 'Authorization: Bearer ' . $credentials[$role]; }
        if (isset($versions[$role])) { $headers[] = 'MCP-Protocol-Version: ' . $versions[$role]; }
        $context = stream_context_create(array('http' => array('method' => 'POST',
            'header' => implode("\r\n", $headers), 'content' => json_encode($message),
            'ignore_errors' => true, 'follow_location' => 0, 'max_redirects' => 0, 'timeout' => 20),
            'ssl' => array('verify_peer' => true, 'verify_peer_name' => true)));
        $stream = @fopen($url, 'rb', false, $context);
        Checks::ensure($stream !== false && isset($http_response_header[0])
            && preg_match('~^HTTP/\S+ ([0-9]{3})~', $http_response_header[0], $match), 'Endpoint reachability');
        try { $body = stream_get_contents($stream, 2097153); }
        finally { fclose($stream); }
        Checks::ensure(is_string($body) && strlen($body) <= 2097152, 'Bounded response body');
        $status = (int) $match[1];
        if ($role === 'missing' || $role === 'invalid') {
            smoke_check_denial($http_response_header, $body);
        }
        $data = json_decode($body);
        if ($status === 200) {
            Checks::ensure($data instanceof stdClass && isset($data->jsonrpc, $data->id)
                && $data->jsonrpc === '2.0' && !$notification && $data->id === $id, 'JSON-RPC response correlation');
            if ($method === 'initialize' && isset($data->result->protocolVersion)
                && in_array($data->result->protocolVersion, array('2025-03-26', '2025-06-18', '2025-11-25'), true)) {
                $versions[$role] = $data->result->protocolVersion;
            }
        }
        return array($status, $data);
    };
}

function smoke_main() {
    $url = getenv('MANTIS_MCP_URL');
    $parts = $url ? parse_url($url) : false;
    Checks::ensure($parts && isset($parts['scheme'], $parts['host'])
        && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['query']) && !isset($parts['fragment'])
        && ($parts['scheme'] === 'https' || ($parts['scheme'] === 'http'
            && in_array($parts['host'], array('localhost', '127.0.0.1', '[::1]'), true))), 'HTTPS MCP URL (or loopback HTTP)');
    $primary = getenv('MANTIS_API_TOKEN');
    $secondary = getenv('MANTIS_API_TOKEN_SECOND');
    foreach (array($primary, $secondary) as $i => $token) {
        if ($i === 1 && ($token === false || $token === '')) { continue; }
        Checks::ensure(is_string($token) && $token !== '' && strlen($token) <= 1024
            && !preg_match('/[\s,]/', $token), 'Personal API token environment value');
    }
    $options = smoke_options('MANTIS_MCP_');
    $options['secondary'] = $secondary !== false && $secondary !== '';
    if ($options['secondary']) { $options['secondary_options'] = smoke_options('MANTIS_MCP_SECOND_'); }
    $request = smoke_transport($url, array('primary' => $primary, 'secondary' => $secondary,
        'missing' => null, 'invalid' => 'not-a-valid-mantis-api-token'));
    (new Checks($request, function($line) { echo smoke_redact($line) . "\n"; }))->run($options);
}

if (realpath(isset($_SERVER['SCRIPT_FILENAME']) ? $_SERVER['SCRIPT_FILENAME'] : '') === __FILE__) {
    try { smoke_main(); }
    catch (Throwable $error) {
        fwrite(STDERR, smoke_redact('FAIL ' . ($error instanceof RuntimeException ? $error->getMessage() : 'Unexpected local check failure.')) . "\n");
        exit(1);
    }
}
