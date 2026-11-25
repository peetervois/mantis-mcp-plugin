<?php
/**
 * SPDX-License-Identifier: BSD-3-Clause
 * Copyright (c) 2026, Peeter Vois
 *
 * Integration harness: real Slim 3 and the selected MantisBT AuthMiddleware and
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
    fwrite(STDERR, "Run composer install and set MANTISBT_SOURCE to a supported MantisBT checkout.\n");
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
define('HEADER_DEPRECATION', 'Deprecation');
define('HEADER_LINK', 'Link');

function require_api($name) {
    // Core dependencies are supplied by the in-memory test datastore below.
}
require $mantis . '/core/classes/MantisPlugin.class.php';
require $mantis . '/api/rest/restcore/AuthMiddleware.php';
require $mantis . '/core/file_api.php';
require __DIR__ . '/upload-storage.php';
require __DIR__ . '/management-fixture.php';
require dirname(__DIR__) . '/MantisMcp/MantisMcp.php';

function reset_state() {
    unset($GLOBALS['g_project_override']);
    if (isset($GLOBALS['test_mantis']['storage_dir'])) {
        foreach (glob($GLOBALS['test_mantis']['storage_dir'] . '/*') as $file) {
            unlink($file);
        }
        rmdir($GLOBALS['test_mantis']['storage_dir']);
    }
    $GLOBALS['test_mantis'] = array(
        'tokens' => array('alice-key' => 1, 'bob-key' => 2, 'disabled-key' => 3),
        'users' => array(1 => 'alice', 2 => 'bob', 3 => 'disabled'),
        'current' => 0, 'writes' => false, 'calls' => array(),
        'max_bytes' => 1048576, 'forced_user' => null,
        'max_download_bytes' => 5242880, 'download_threshold' => 25,
        'allow_download_own' => false, 'attachments' => array(),
        'file_response_override' => null,
        'max_upload_bytes' => 524288, 'upload_threshold' => 25,
        'max_file_size' => 5242880, 'allow_uploads' => true,
        'readonly' => false, 'file_method' => DATABASE, 'last_file_id' => 300,
        'storage_failure' => null, 'attachment_cache' => array(), 'use_attachment_cache' => false,
        'disallowed_files' => 'svg', 'allowed_files' => '', 'upload_paths' => array(),
        'delete_threshold' => 25, 'delete_own' => false, 'notes' => array(),
        'last_note_id' => 2000, 'note_edits' => array(), 'events' => array(), 'moderation' => array(),
        'relationships' => array(), 'last_relationship_id' => 400, 'project_configs' => array(),
        'config' => array('status_enum_string' => '10:new,20:feedback,30:acknowledged,40:confirmed,50:assigned,60:working,70:resolved,80:testing,90:closed',
            'status_enum_workflow' => array(10 => '50:assigned,90:closed', 20 => '10:new,50:assigned,90:closed',
                30 => '10:new,90:closed', 40 => '10:new,90:closed', 50 => '20:feedback,60:working,90:closed',
                60 => '20:feedback,50:assigned,70:resolved,90:closed', 70 => '50:assigned,80:testing,90:closed',
                80 => '50:assigned,90:closed', 90 => '20:feedback'),
            'set_status_threshold' => array(), 'bug_submit_status' => 10,
            'report_bug_threshold' => 25, 'update_bug_status_threshold' => 25,
            'update_bug_threshold' => 25, 'webservice_readonly_access_level_threshold' => 10,
            'add_bugnote_threshold' => 25, 'set_view_status_threshold' => 25,
            'bugnote_user_edit_threshold' => 25, 'update_bugnote_threshold' => 55,
            'default_bugnote_view_status' => 10, 'reassign_on_feedback' => false),
    );
    $bytes = str_repeat(implode('', array_map('chr', range(0, 255))), 1385) . 'final-bytes';
    foreach (array(array(219, 94, 1302, array(1)), array(220, 96, 1303, array(1)),
        array(221, 96, 0, array(1, 2)), array(222, 95, 0, array(2))) as $file) {
        $GLOBALS['test_mantis']['attachments'][$file[0]] = array(
            'id' => $file[0], 'issue_id' => $file[1], 'bugnote_id' => $file[2],
            'note_readers' => $file[3], 'user_id' => $file[1] === 95 ? 2 : 1,
            'filename' => 'LOGO_TAURIA_OÜ.jpg', 'file_type' => 'image/jpeg; charset=binary',
            'filesize' => strlen($bytes), 'bytes' => $bytes,
        );
        if ($file[2] > 0) {
            $GLOBALS['test_mantis']['notes'][$file[2]] = array('id' => $file[2], 'bug_id' => $file[1],
                'reporter_id' => 1, 'text' => 'Original note', 'view_state' => 50, 'readers' => $file[3]);
        }
    }
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
    if ($name === 'core_path') { return getenv('MANTISBT_SOURCE') . '/core/'; }
    return $name === 'path' ? 'https://central.tauria.ee/mantis/' : $default;
}
function config_get($name, $default = null, $user = null, $project = null) {
    $state = &$GLOBALS['test_mantis'];
    $project = $project === null ? (isset($GLOBALS['g_project_override']) ? $GLOBALS['g_project_override'] : 1) : $project;
    if (isset($state['project_configs'][$project]) && array_key_exists($name, $state['project_configs'][$project])) {
        return $state['project_configs'][$project][$name];
    }
    if (array_key_exists($name, $state['config'])) { return $state['config'][$name]; }
    $values = array('view_attachments_threshold' => 10,
        'download_attachments_threshold' => $GLOBALS['test_mantis']['download_threshold'],
        'allow_view_own_attachments' => false,
        'allow_download_own_attachments' => $GLOBALS['test_mantis']['allow_download_own'],
        'view_bug_threshold' => 10, 'document_files_prefix' => 'doc',
        'allow_file_upload' => $GLOBALS['test_mantis']['allow_uploads'],
        'allow_reporter_upload' => OFF,
        'upload_bug_file_threshold' => $GLOBALS['test_mantis']['upload_threshold'],
        'max_file_size' => $GLOBALS['test_mantis']['max_file_size'],
        'allowed_files' => $GLOBALS['test_mantis']['allowed_files'],
        'disallowed_files' => $GLOBALS['test_mantis']['disallowed_files'],
        'file_upload_method' => $GLOBALS['test_mantis']['file_method'],
        'attachments_file_permissions' => 0400);
    $values['delete_attachments_threshold'] = $state['delete_threshold'];
    $values['allow_delete_own_attachments'] = $state['delete_own'];
    return isset($values[$name]) ? $values[$name] : $default;
}
function bug_get_attachments($issueId) {
    $state = &$GLOBALS['test_mantis'];
    if ($state['use_attachment_cache'] && isset($state['attachment_cache'][$issueId])) {
        return $state['attachment_cache'][$issueId];
    }
    $rows = array_values(array_filter($state['attachments'],
        function($row) use ($issueId) { return $row['issue_id'] === $issueId; }));
    $state['attachment_cache'][$issueId] = $rows;
    return $rows;
}
function bug_get_field($issueId, $field) {
    return $field === 'project_id' ? 1 : null;
}
function bugnote_get_field($noteId, $field) {
    if (isset($GLOBALS['test_mantis']['notes'][$noteId])) {
        return $GLOBALS['test_mantis']['notes'][$noteId][$field];
    }
    foreach ($GLOBALS['test_mantis']['attachments'] as $file) {
        if ($file['bugnote_id'] === $noteId) {
            return $field === 'bug_id' ? $file['issue_id'] : null;
        }
    }
    return null;
}
function access_has_bug_level($threshold, $issueId, $userId = null) {
    $userId = $userId === null ? auth_get_current_user_id() : $userId;
    return $threshold <= 55 && ($issueId === 93 + $userId || $issueId === 96);
}
function access_has_bugnote_level($threshold, $noteId, $userId = null) {
    $userId = $userId === null ? auth_get_current_user_id() : $userId;
    foreach ($GLOBALS['test_mantis']['attachments'] as $file) {
        if ($file['bugnote_id'] === $noteId) {
            return access_has_bug_level($threshold, $file['issue_id'], $userId)
                && in_array($userId, $file['note_readers'], true);
        }
    }
    if (isset($GLOBALS['test_mantis']['notes'][$noteId])) {
        $note = $GLOBALS['test_mantis']['notes'][$noteId];
        return access_has_bug_level($threshold, $note['bug_id'], $userId) && in_array($userId, $note['readers'], true);
    }
    return false;
}
function plugin_config_get($name, $default = null, $global = false) {
    if ($name === 'enable_write_tools') {
        return $GLOBALS['test_mantis']['writes'];
    }
    if ($name === 'max_request_bytes') {
        return $GLOBALS['test_mantis']['max_bytes'];
    }
    if ($name === 'max_download_bytes') {
        return $GLOBALS['test_mantis']['max_download_bytes'];
    }
    if ($name === 'max_upload_bytes') {
        return $GLOBALS['test_mantis']['max_upload_bytes'];
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
    if ($id !== 93 + auth_get_current_user_id() && $id !== 96) {
        return $response->withStatus(404);
    }
    return $response->withJson(array('issues' => array(array('id' => $id,
        'summary' => 'Visible to ' . user_get_username(auth_get_current_user_id())))));
}
function rest_issue_files_get($request, $response, $path) {
    record_call('files', $request, $path);
    if ($GLOBALS['test_mantis']['file_response_override'] !== null) {
        return $response->withJson($GLOBALS['test_mantis']['file_response_override']);
    }
    // Deliberately grants no extra permission protection: the adapter must enforce it.
    $file = $GLOBALS['test_mantis']['attachments'][$path['file_id']];
    return $response->withJson(array('files' => array(array(
        'id' => $file['id'], 'filename' => file_get_display_name($file['filename']),
        'size' => $file['filesize'], 'content_type' => $file['file_type'],
        'content' => base64_encode($file['bytes']),
    ))));
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
