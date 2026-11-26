<?php
/** SPDX-License-Identifier: BSD-3-Clause */

// Load exact upstream helper bodies without loading conflicting fixture APIs.
// No MantisBT implementation source is copied into this repository.
function load_native_function($path, $name) {
    $source = file_get_contents($path);
    $start = strpos($source, 'function ' . $name . '(');
    if ($start === false) { throw new RuntimeException('Native helper missing: ' . $name); }
    $tokens = token_get_all('<?php ' . substr($source, $start));
    $code = '';
    $depth = 0;
    $body = false;
    foreach ($tokens as $token) {
        if (is_array($token)) {
            if ($token[0] === T_OPEN_TAG) { continue; }
            if ($token[0] === T_CURLY_OPEN || $token[0] === T_DOLLAR_OPEN_CURLY_BRACES) { ++$depth; }
            $code .= $token[1];
        } else {
            $code .= $token;
            if ($token === '{') { ++$depth; $body = true; }
            if ($token === '}' && --$depth === 0 && $body) {
                eval('use Mantis\\Exceptions\\ClientException;' . $code);
                return;
            }
        }
    }
    throw new RuntimeException('Incomplete native helper: ' . $name);
}

foreach (array('BUG_DUPLICATE' => 0, 'BUG_RELATED' => 1, 'BUG_DEPENDANT' => 2,
    'BUG_BLOCKS' => 3, 'BUG_HAS_DUPLICATE' => 4, 'BUG_REL_NONE' => -1, 'BUG_REL_ANY' => -2,
    'BUGNOTE' => 0, 'VS_PRIVATE' => 50, 'VS_PUBLIC' => 10, 'NO_USER' => 0, 'FILE_DELETED' => 10,
    'ERROR_RELATIONSHIP_SAME_BUG' => 1600, 'ERROR_RELATIONSHIP_ACCESS_LEVEL_TO_DEST_BUG_TOO_LOW' => 1601,
    'ERROR_RELATIONSHIP_NOT_FOUND' => 1602, 'ERROR_FILE_NOT_FOUND' => 506,
    'BUG_ADD_RELATIONSHIP' => 22, 'BUG_REPLACE_RELATIONSHIP' => 23,
    'BUG_DEL_RELATIONSHIP' => 24) as $name => $value) {
    if (!defined($name)) { define($name, $value); }
}
require $mantis . '/core/classes/MantisEnum.class.php';
load_native_function($mantis . '/core/bug_api.php', 'bug_check_workflow');
load_native_function($mantis . '/core/access_api.php', 'access_get_status_threshold');
load_native_function($mantis . '/core/bugnote_api.php', 'bugnote_date_update');
require $mantis . '/core/relationship_api.php';
require $mantis . '/core/commands/IssueRelationshipAddCommand.php';
require $mantis . '/core/commands/IssueRelationshipDeleteCommand.php';
require $mantis . '/core/commands/IssueNoteAddCommand.php';
require $mantis . '/core/commands/ConfigsGetCommand.php';

function config_is_set($key) { return !in_array($key, $GLOBALS['test_mantis']['undefined_configs'], true); }
function config_is_private($key) { return in_array($key, $GLOBALS['test_mantis']['private_configs'], true); }
function project_exists($id) { return in_array($id, array(1, 2), true); }
function user_exists($id) { return in_array($id, array(1, 2), true); }
function current_user_is_administrator() { return false; }

function lang_get($name) { return $name; }
function get_enum_element($kind, $id) {
    $names = MantisEnum::getAssocArrayIndexedByValues(config_get($kind . '_enum_string'));
    return isset($names[$id]) ? $names[$id] : 'unknown';
}
function helper_parse_id($value, $field) { return (int) $value; }
function bug_ensure_exists($id) {
    if (!in_array($id, array(94, 95, 96), true)) { throw new RuntimeException('Missing fixture issue'); }
}
function bug_cache_array_rows($ids) {}
function bug_exists($id) { return in_array($id, array(94, 95, 96), true); }
function email_relationship_added($source, $target, $type, $notify) {}
function email_relationship_deleted($source, $target, $type) {}

// Database boundary for the actual native relationship API, including its caches.
function management_relationship_query($query, $parameters) {
    $state = &$GLOBALS['test_mantis'];
    if (stripos($query, 'INSERT INTO') !== false || stripos($query, 'UPDATE ') === 0) {
        $id = stripos($query, 'INSERT INTO') !== false ? ++$state['last_relationship_id'] : $parameters[3];
        $state['relationships'][$id] = (object) array('id' => $id,
            'src_bug_id' => $parameters[0], 'dest_bug_id' => $parameters[1],
            'src_project_id' => 1, 'dest_project_id' => 1, 'type' => $parameters[2]);
        return array();
    }
    if (stripos($query, 'DELETE FROM') !== false) {
        unset($state['relationships'][$parameters[0]]);
        return array();
    }
    if (stripos($query, 'SELECT ') !== 0) { throw new RuntimeException('Unexpected relationship query'); }
    $rows = array();
    foreach ($state['relationships'] as $row) {
        if (strpos($query, 'WHERE id=') !== false) {
            $match = $row->id === $parameters[0];
        } elseif (strpos($query, 'WHERE (source_bug_id=') !== false) {
            $match = ($row->src_bug_id === $parameters[0] && $row->dest_bug_id === $parameters[1])
                || ($row->src_bug_id === $parameters[1] && $row->dest_bug_id === $parameters[0]);
        } elseif (strpos($query, 'WHERE source_bug_id=') !== false) {
            $match = $row->src_bug_id === $parameters[0];
        } elseif (strpos($query, 'WHERE destination_bug_id=') !== false) {
            $match = $row->dest_bug_id === $parameters[0];
        } else { throw new RuntimeException('Unexpected relationship SELECT'); }
        if ($match) {
            $rows[] = array('id' => $row->id, 'source_bug_id' => $row->src_bug_id,
                'destination_bug_id' => $row->dest_bug_id, 'relationship_type' => $row->type, 'project_id' => 1);
        }
    }
    return $rows;
}
function helper_duration_to_minutes($duration, $field = null) { return 0; }
function helper_parse_view_state($value) { return $value['name'] === 'private' ? VS_PRIVATE : VS_PUBLIC; }
function bugnote_exists($id) { return isset($GLOBALS['test_mantis']['notes'][$id]); }
function bugnote_add($issue, $text, $duration, $private, $type, $attribute, $reporter) {
    $state = &$GLOBALS['test_mantis'];
    $id = ++$state['last_note_id'];
    $state['notes'][$id] = array('id' => $id, 'bug_id' => $issue, 'reporter_id' => $reporter,
        'text' => $text, 'view_state' => $private ? VS_PRIVATE : VS_PUBLIC,
        'readers' => $private ? array($reporter) : array(1, 2));
    return $id;
}
function bugnote_process_mentions($issue, $note, $text) { return array(); }
function email_bugnote_add($id, $files, $exclude) {}
function event_signal($event, $arguments = array()) {
    $GLOBALS['test_mantis']['events'][] = $event;
    return isset($GLOBALS['test_mantis']['moderation'][$event]) && $GLOBALS['test_mantis']['moderation'][$event];
}
function db_field_exists($name, $table) { return true; }

function management_call($name, $arguments = array(), $token = 'alice-key') {
    return decoded(send_request(tool_call($name, (object) $arguments), $token));
}

