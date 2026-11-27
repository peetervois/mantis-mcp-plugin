<?php
/**
 * SPDX-License-Identifier: BSD-3-Clause
 * Copyright (c) 2026, Peeter Vois
 *
 * Fixture database boundary. Upload tests execute the actual upstream command,
 * file_add, permission helpers, MIME detection and DATABASE/DISK storage code.
 */

foreach (array('DISK' => 1, 'DATABASE' => 2, 'ALL_PROJECTS' => 0, 'FILE_ADDED' => 9,
    'DB_FIELD_SIZE_FILENAME' => 250, 'ERROR_EMPTY_FIELD' => 11, 'ERROR_ACCESS_DENIED' => 13,
    'ERROR_INVALID_FIELD_VALUE' => 29, 'ERROR_FILE_TOO_BIG' => 500, 'ERROR_FILE_NOT_ALLOWED' => 501,
    'ERROR_FILE_DUPLICATE' => 502, 'ERROR_FILE_INVALID_UPLOAD_PATH' => 503,
    'ERROR_FILE_NO_UPLOAD_FAILURE' => 504, 'ERROR_FILE_MOVE_FAILED' => 505,
    'ERROR_FILE_NAME_TOO_LONG' => 507, 'ERROR_UPLOAD_FAILURE' => 508,
    'ERROR_BUG_READ_ONLY_ACTION_DENIED' => 1103, 'ERROR_GENERIC' => 1) as $name => $value) {
    define($name, $value);
}
if (is_file($mantis . '/core/exceptions/LocalizedErrorMessageTrait.php')) {
    require $mantis . '/core/exceptions/LocalizedErrorMessageTrait.php';
}
require $mantis . '/core/exceptions/MantisException.php';
require $mantis . '/core/exceptions/ClientException.php';
require $mantis . '/core/exceptions/ServiceException.php';
require $mantis . '/core/commands/Command.php';
require $mantis . '/core/commands/IssueFileAddCommand.php';

function error_string($code, $parameters = array()) { return 'Fixture error ' . $code; }
function auth_ensure_user_authenticated() {
    if (!auth_get_current_user_id()) { throw new RuntimeException('Unauthenticated fixture'); }
}
function helper_parse_issue_id($id) { return (int) $id; }
function helper_get_current_project() { return 1; }
function bug_get($id, $details = true) {
    return (object) array('id' => $id, 'project_id' => 1, 'status' => 60, 'handler_id' => 1, 'reporter_id' => 1);
}
function bug_is_readonly($id) { return $GLOBALS['test_mantis']['readonly']; }
function bug_is_user_reporter($id, $user) { return $id === 93 + $user; }
function access_has_project_level($threshold, $project, $user = null) { return $threshold <= 55; }
function bug_attachments_clear_cache($id) { unset($GLOBALS['test_mantis']['attachment_cache'][$id]); }
function ini_get_bool($name) { return true; }
function ini_get_number($name) {
    return isset($GLOBALS['test_mantis']['php_limits'][$name]) ? $GLOBALS['test_mantis']['php_limits'][$name]
        : ($name === 'upload_max_filesize' ? 2097152 : 8388608);
}
function antispam_check() {}
function is_blank($value) { return $value === null || trim($value) === ''; }
function project_get_field($id, $field) {
    return $field === 'file_path' && isset($GLOBALS['test_mantis']['storage_dir'])
        ? $GLOBALS['test_mantis']['storage_dir'] . '/' : '';
}
function db_param_push() {}
function db_param() { return '?'; }
function db_get_table($name) { return 'test_' . $name; }
function db_prepare_binary_string($bytes) { return $bytes; }
function db_is_oracle() { return false; }
function db_now() { return time(); }
function db_insert_id($table) {
    return $GLOBALS['test_mantis'][$table === 'test_bug_relationship' ? 'last_relationship_id' : 'last_file_id'];
}
function db_result($result) { return reset($result[0]); }
function db_fetch_array(&$result) { return array_shift($result) ?: false; }
function history_log_event_special($id, $type, $name, $note) {}
function bug_update_date($id) {
    if ($GLOBALS['test_mantis']['storage_failure'] === 'after_insert') {
        throw new RuntimeException('Simulated failure after persistence');
    }
}
function db_query($query, $parameters = array()) {
    $state = &$GLOBALS['test_mantis'];
    if (strpos($query, '{bug_relationship}') !== false) {
        return management_relationship_query($query, $parameters);
    }
    if (strpos($query, 'UPDATE {bugnote} SET last_modified=') === 0) {
        $state['notes'][$parameters[1]]['last_modified'] = $parameters[0];
        $state['note_edits'][] = $parameters[1];
        return array();
    }
    if (stripos($query, 'SELECT COUNT(*) FROM') !== false) {
        $count = count(array_filter($state['attachments'], function($row) use ($parameters) {
            return $row['filename'] === $parameters[0] && $row['issue_id'] === $parameters[1];
        }));
        return array(array('count' => $count));
    }
    if (stripos($query, 'SELECT count(*)') !== false) { return array(array('count' => 0)); }
    if (preg_match('/INSERT INTO .*?\(\s*(.*?)\s*\)\s*VALUES/s', $query, $matches)) {
        if ($state['storage_failure'] === 'before_insert') {
            throw new RuntimeException('Simulated storage failure containing secret bytes');
        }
        $row = array_combine(array_map('trim', explode(',', $matches[1])), $parameters);
        $id = ++$state['last_file_id'];
        $row['id'] = $id;
        $row['issue_id'] = $row['bug_id'];
        $row['bytes'] = $row['content'];
        $row['note_readers'] = isset($state['notes'][$row['bugnote_id']])
            ? $state['notes'][$row['bugnote_id']]['readers'] : array(auth_get_current_user_id());
        if ($state['storage_failure'] === 'corrupt') { $row['content'] = $row['bytes'] = str_repeat('x', $row['filesize']); }
        $state['attachments'][$id] = $row;
        return array();
    }
    if (stripos($query, 'SELECT * FROM {bug_file}') !== false) {
        return array($state['attachments'][$parameters[0]]);
    }
    if (preg_match('/SELECT (\w+) FROM test_bug_file WHERE id=/', $query, $matches)) {
        return isset($state['attachments'][$parameters[0]])
            ? array(array($matches[1] => $state['attachments'][$parameters[0]][$matches[1]])) : array(array(false));
    }
    if (stripos($query, 'DELETE FROM test_bug_file') !== false) {
        unset($state['attachments'][$parameters[0]]);
        return array();
    }
    throw new RuntimeException('Unexpected fixture database query');
}
