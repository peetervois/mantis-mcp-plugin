<?php
/**
 * SPDX-License-Identifier: BSD-3-Clause
 * Copyright (c) 2026, Peeter Vois
 *
 * Integration harness: real Slim 3 and MantisBT 2.26.4's AuthMiddleware and
 * MantisPlugin base class, with a deliberately small in-memory Mantis datastore.
 */

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');
set_error_handler(function($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
$mantis = getenv('MANTISBT_SOURCE');
if (!is_file($autoload) || !$mantis
    || !is_file($mantis . '/api/rest/restcore/AuthMiddleware.php')) {
    fwrite(STDERR, "Run composer install and set MANTISBT_SOURCE to a MantisBT 2.26.4 checkout.\n");
    exit(1);
}
require $autoload;

define('OFF', 0);
define('ON', 1);
define('HTTP_STATUS_SUCCESS', 200);
define('HTTP_STATUS_UNAUTHORIZED', 401);
define('HTTP_STATUS_FORBIDDEN', 403);
define('HTTP_STATUS_NOT_FOUND', 404);
define('HEADER_AUTHORIZATION', 'Authorization');
define('HEADER_USERNAME', 'X-Mantis-Username');
define('HEADER_LOGIN_METHOD', 'X-Mantis-LoginMethod');
define('LOGIN_METHOD_COOKIE', 'cookie');
define('LOGIN_METHOD_API_TOKEN', 'api-token');
define('LOGIN_METHOD_ANONYMOUS', 'anonymous');
define('ATTRIBUTE_FORCE_API_ENABLED', 'force_enable_api');

function require_api($name) {
    // Core dependencies are supplied by the in-memory test datastore below.
}
require $mantis . '/core/classes/MantisPlugin.class.php';
require $mantis . '/api/rest/restcore/AuthMiddleware.php';
require dirname(__DIR__) . '/MantisMcp/MantisMcp.php';

function reset_state() {
    $GLOBALS['test_mantis'] = array(
        'tokens' => array('alice-key' => 1, 'bob-key' => 2, 'disabled-key' => 3),
        'users' => array(1 => 'alice', 2 => 'bob', 3 => 'disabled'),
        'current' => 0, 'writes' => false, 'calls' => array(),
        'max_bytes' => 1048576, 'forced_user' => null,
    );
}

function api_token_get_user($token) {
    return isset($GLOBALS['test_mantis']['tokens'][$token])
        ? $GLOBALS['test_mantis']['tokens'][$token] : false;
}
function auth_get_current_user_id() {
    return $GLOBALS['test_mantis']['current'];
}
function auth_is_user_authenticated() {
    return auth_get_current_user_id() > 0;
}
function user_get_username($id) {
    return $GLOBALS['test_mantis']['users'][$id];
}
function mci_check_login($username, $password) {
    $id = api_token_get_user($password);
    if (!$id || $id === 3 || user_get_username($id) !== $username) {
        return false;
    }
    $GLOBALS['test_mantis']['current'] = $GLOBALS['test_mantis']['forced_user'] ?: $id;
    return true;
}
function mci_has_readonly_access() {
    return true;
}
function config_get_global($name, $default = null) {
    return $name === 'path' ? 'https://central.tauria.ee/mantis/' : $default;
}
function plugin_config_get($name, $default = null, $global = false) {
    if ($name === 'enable_write_tools') {
        return $GLOBALS['test_mantis']['writes'];
    }
    if ($name === 'max_request_bytes') {
        return $GLOBALS['test_mantis']['max_bytes'];
    }
    return $default;
}
function plugin_route_group() {
    return '/plugins/MantisMcp';
}

function record_call($name, $request, $path) {
    $GLOBALS['test_mantis']['calls'][] = array(
        'name' => $name, 'user' => auth_get_current_user_id(),
        'method' => $request->getMethod(), 'query' => $request->getQueryParams(),
        'payload' => $request->getParsedBody(), 'path' => $path,
    );
}
function rest_user_get_me($request, $response, $path) {
    record_call('user', $request, $path);
    return $response->withJson(array('id' => auth_get_current_user_id(),
        'name' => user_get_username(auth_get_current_user_id()), 'access_level' => array('id' => 25)));
}
function rest_projects_get($request, $response, $path) {
    record_call('projects', $request, $path);
    $id = isset($path['id']) ? $path['id'] : auth_get_current_user_id();
    if ($id !== auth_get_current_user_id()) {
        return $response->withStatus(404);
    }
    return $response->withJson(array('projects' => array(array('id' => $id, 'name' => 'Project ' . $id))));
}
function rest_config_get($request, $response, $path) {
    record_call('options', $request, $path);
    return $response->withJson(array('configs' => array(array(
        'option' => 'status_enum_string', 'value' => array(array('id' => 10, 'name' => 'new'))))));
}
function rest_issue_get($request, $response, $path) {
    record_call('issues', $request, $path);
    $id = isset($path['id']) ? $path['id'] : 93 + auth_get_current_user_id();
    if ($id !== 93 + auth_get_current_user_id()) {
        return $response->withStatus(404);
    }
    return $response->withJson(array('issues' => array(array('id' => $id,
        'summary' => 'Visible to ' . user_get_username(auth_get_current_user_id())))));
}
function rest_issue_add($request, $response, $path) {
    record_call('create', $request, $path);
    if (auth_get_current_user_id() !== 1) {
        return $response->withStatus(403);
    }
    $payload = $request->getParsedBody();
    return $response->withJson(array('issue' => array(
        'id' => 100, 'summary' => $payload['summary'], 'project' => $payload['project'],
        'reporter' => array('id' => auth_get_current_user_id()),
        'description' => str_repeat('x', 10000))), 201);
}
function rest_issue_update($request, $response, $path) {
    record_call('update', $request, $path);
    if (auth_get_current_user_id() !== 1) {
        return $response->withStatus(403);
    }
    return $response->withJson(array('issues' => array(array('id' => $path['id'],
        'summary' => 'Updated', 'status' => array('id' => 50)))));
}
function rest_issue_note_add($request, $response, $path) {
    record_call('note', $request, $path);
    return $response->withJson(array('note' => array('id' => 123,
        'reporter' => array('id' => auth_get_current_user_id()))), 201);
}

function send_request($message, $token = 'alice-key', array $options = array()) {
    // A PHP-FPM request starts with fresh process-local request state.
    $GLOBALS['test_mantis']['current'] = isset($options['cookie_user']) ? $options['cookie_user'] : 0;
    $app = new \Slim\App(array('settings' => array('displayErrorDetails' => false)));
    $app->add(new \AuthMiddleware());
    $plugin = new \MantisMcpPlugin('MantisMcp');
    $plugin->routes('EVENT_REST_API_ROUTES', array('app' => $app));
    $app->get('/unrelated', function($request, $response) {
        return $response->withJson(array('unrelated' => true));
    });
    $request = \Slim\Http\Request::createFromEnvironment(\Slim\Http\Environment::mock(array(
        'REQUEST_METHOD' => isset($options['method']) ? $options['method'] : 'POST',
        'REQUEST_URI' => isset($options['path']) ? $options['path'] : '/plugins/MantisMcp/mcp',
        'SCRIPT_NAME' => isset($options['script_name']) ? $options['script_name'] : '/index.php',
        'CONTENT_TYPE' => isset($options['type']) ? $options['type'] : 'application/json',
    )));
    if ($token !== null) {
        $request = $request->withHeader('Authorization', 'Bearer ' . $token);
    }
    foreach (isset($options['headers']) ? $options['headers'] : array() as $name => $value) {
        $request = $value === null ? $request->withoutHeader($name) : $request->withHeader($name, $value);
    }
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, is_string($message) ? $message : json_encode($message));
    rewind($stream);
    $request = $request->withBody(new \Slim\Http\Body($stream));
    return $app->process($request, new \Slim\Http\Response());
}

function rpc($method, $params = null, $id = 1) {
    return (object) array('jsonrpc' => '2.0', 'id' => $id, 'method' => $method,
        'params' => $params === null ? new stdClass() : $params);
}
function tool_call($name, $arguments = null) {
    return rpc('tools/call', (object) array('name' => $name,
        'arguments' => $arguments === null ? new stdClass() : $arguments));
}
function decoded($response) {
    return json_decode((string) $response->getBody());
}
function check($condition, $message = 'Assertion failed') {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function same($expected, $actual, $message = 'Values differ') {
    check($expected === $actual, $message);
}
