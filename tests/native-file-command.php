<?php
/**
 * Isolated regression: actual 2.26.4 file REST handler and command with strict
 * deprecation handling. Storage/authentication dependencies use test fixtures.
 * SPDX-License-Identifier: BSD-3-Clause
 * Copyright (c) 2026, Peeter Vois
 */

// Preload Slim before applying the strict handler to the native command.
error_reporting(E_ALL & ~E_DEPRECATED);
require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/MantisMcp/core/NativeRest.php';
$mantis = getenv('MANTISBT_SOURCE');
if (!$mantis || !is_file($mantis . '/core/commands/IssueFileGetCommand.php')) {
    fwrite(STDERR, "Set MANTISBT_SOURCE to the MantisBT 2.26.4 source.\n");
    exit(1);
}
define('HTTP_STATUS_SUCCESS', 200);
function require_api($name) {}
function auth_ensure_user_authenticated() {}
function helper_parse_issue_id($id) { return (int) $id; }
function bug_get($id, $extended) { return (object) array('project_id' => 34); }
function auth_get_current_user_id() { return 13; }
function helper_get_current_project() { return 34; }
function file_get_visible_attachments($issueId) {
    return array(array('id' => 220, 'user_id' => 1, 'date_added' => 1700000000,
        'display_name' => 'IconLDC-bt3.png', 'size' => strlen($GLOBALS['fixture_bytes']), 'exists' => true));
}
function file_get_content($id) { return array('type' => 'image/png', 'content' => $GLOBALS['fixture_bytes']); }
function mci_account_get_array_by_id($id) { return array('id' => $id); }
class ApiObjectFactory {
    public static function datetimeString($timestamp) { return date(DATE_ATOM, $timestamp); }
}
require $mantis . '/core/commands/Command.php';
require $mantis . '/core/commands/IssueFileGetCommand.php';
$g_app = new \Slim\App();
require $mantis . '/api/rest/restcore/issues_rest.php';

$GLOBALS['fixture_bytes'] = file_get_contents(dirname(__DIR__) . '/chatgpt/tauria-tasks/assets/IconLDC-bt3.png');
$request = \Slim\Http\Request::createFromEnvironment(\Slim\Http\Environment::mock());
$response = new \Slim\Http\Response();
$path = array('id' => 1006, 'file_id' => 220);
error_reporting(E_ALL);
$strict = function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
};
set_error_handler($strict);

try {
    if (PHP_VERSION_ID >= 80200) {
        $reproduced = false;
        try {
            rest_issue_files_get($request, $response, $path);
        } catch (ErrorException $error) {
            $reproduced = $error->getSeverity() === E_DEPRECATED
                && strpos($error->getMessage(), 'IssueFileGetCommand::$user_id') !== false;
        }
        if (!$reproduced) {
            throw new RuntimeException('Expected the unpatched native dynamic-property failure.');
        }
    }
    $result = \Tauria\MantisMcp\NativeRest::invoke('rest_issue_files_get', $request, $response, $path, true);
    $data = json_decode((string) $result->getBody());
    if ($result->getStatusCode() !== 200
        || base64_decode($data->files[0]->content, true) !== $GLOBALS['fixture_bytes']) {
        throw new RuntimeException('Native file response did not preserve original bytes.');
    }
    if (set_error_handler($strict) !== $strict) {
        throw new RuntimeException('The original error handler was not restored.');
    }
    $propagated = false;
    try {
        \Tauria\MantisMcp\NativeRest::invoke(function() {
            trigger_error('Unrelated fixture warning', E_USER_WARNING);
        }, $request, $response, $path, true);
    } catch (ErrorException $error) {
        $propagated = $error->getSeverity() === E_USER_WARNING;
    }
    if (!$propagated || set_error_handler($strict) !== $strict) {
        throw new RuntimeException('Unrelated errors must propagate and restore the original handler.');
    }
    if (PHP_VERSION_ID >= 80200) {
        $propagated = false;
        try {
            \Tauria\MantisMcp\NativeRest::invoke(function() {
                $command = new IssueFileGetCommand(array());
                $command->another_property = 1;
            }, $request, $response, $path, true);
        } catch (ErrorException $error) {
            $propagated = strpos($error->getMessage(), 'another_property') !== false;
        }
        if (!$propagated || set_error_handler($strict) !== $strict) {
            throw new RuntimeException('The compatibility rule must not suppress other dynamic properties.');
        }
    }
    echo "PASS actual native file REST handler preserves PNG bytes under strict error handling\n";
} finally {
    restore_error_handler();
}
