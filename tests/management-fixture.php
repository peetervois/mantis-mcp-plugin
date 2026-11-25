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
    'ERROR_RELATIONSHIP_NOT_FOUND' => 1602, 'ERROR_FILE_NOT_FOUND' => 506) as $name => $value) {
    if (!defined($name)) { define($name, $value); }
}
require $mantis . '/core/classes/MantisEnum.class.php';
load_native_function($mantis . '/core/bug_api.php', 'bug_check_workflow');
load_native_function($mantis . '/core/access_api.php', 'access_get_status_threshold');
$relationshipSource = file_get_contents($mantis . '/core/relationship_api.php');
$start = strpos($relationshipSource, '$g_relationships = array();');
$end = strpos($relationshipSource, "\nif( file_exists", $start);
eval(substr($relationshipSource, $start, $end - $start));
foreach (array('relationship_type_ensure_valid', 'relationship_get_complementary_type',
    'relationship_get_name_for_api', 'relationship_get_id_from_api_name',
    'relationship_get_description_src_side') as $function) {
    load_native_function($mantis . '/core/relationship_api.php', $function);
}
require $mantis . '/core/commands/IssueRelationshipAddCommand.php';
require $mantis . '/core/commands/IssueRelationshipDeleteCommand.php';
require $mantis . '/core/commands/IssueNoteAddCommand.php';

function lang_get($name) { return $name; }
function get_enum_element($kind, $id) {
    $names = MantisEnum::getAssocArrayIndexedByValues(config_get($kind . '_enum_string'));
    return isset($names[$id]) ? $names[$id] : 'unknown';
}
function helper_parse_id($value, $field) { return (int) $value; }
function bug_ensure_exists($id) {
    if (!in_array($id, array(94, 95, 96), true)) { throw new RuntimeException('Missing fixture issue'); }
}
function relationship_get_all($id, &$different) {
    $different = false;
    return array_values(array_filter($GLOBALS['test_mantis']['relationships'], function($row) use ($id) {
        return $row->src_bug_id === $id || $row->dest_bug_id === $id;
    }));
}
function relationship_upsert($source, $target, $type) {
    $state = &$GLOBALS['test_mantis'];
    foreach ($state['relationships'] as $row) {
        if ($row->src_bug_id === $source && $row->dest_bug_id === $target) {
            $row->type = $type;
            return $row->id;
        }
        if ($row->src_bug_id === $target && $row->dest_bug_id === $source) {
            $row->type = relationship_get_complementary_type($type);
            return $row->id;
        }
    }
    $id = ++$state['last_relationship_id'];
    $state['relationships'][$id] = (object) array('id' => $id, 'src_bug_id' => $source,
        'dest_bug_id' => $target, 'src_project_id' => 1, 'dest_project_id' => 1, 'type' => $type);
    return $id;
}
function relationship_get_linked_bug_id($id, $source) {
    foreach ($GLOBALS['test_mantis']['relationships'] as $row) {
        if ($row->id === $id && $row->src_bug_id === $source) { return $row->dest_bug_id; }
        if ($row->id === $id && $row->dest_bug_id === $source) { return $row->src_bug_id; }
    }
    throw new RuntimeException('Relationship is not on this fixture issue');
}
function relationship_delete($id) { unset($GLOBALS['test_mantis']['relationships'][$id]); }
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
function bugnote_set_last_modified($id) { $GLOBALS['test_mantis']['note_edits'][] = $id; }
function email_bugnote_add($id, $files, $exclude) {}
function event_signal($event, $arguments = array()) {
    $GLOBALS['test_mantis']['events'][] = $event;
    return isset($GLOBALS['test_mantis']['moderation'][$event]) && $GLOBALS['test_mantis']['moderation'][$event];
}
function db_field_exists($name, $table) { return true; }

function management_call($name, $arguments = array(), $token = 'alice-key') {
    return decoded(send_request(tool_call($name, (object) $arguments), $token));
}

