<?php
/** SPDX-License-Identifier: BSD-3-Clause */
function new_note_file($extra = array(), $token = 'alice-key') {
    $file = (array) upload_arguments();
    unset($file['issue_id']);
    return decoded(send_request(tool_call('mantis_add_note', (object) ($extra + array(
        'issue_id' => 96, 'text' => 'Screenshot explanation', 'attachment' => (object) $file))), $token));
}

test_case('creates a public note and original PNG together using the actual native note command', function() {
    $GLOBALS['test_mantis']['writes'] = true;
    $before = staging_files();
    $result = new_note_file(array('private' => false))->result;
    same(false, $result->isError);
    $note = $GLOBALS['test_mantis']['notes'][$result->structuredContent->note->id];
    same('Screenshot explanation', $note['text']);
    same(1, $note['reporter_id']);
    same(10, $note['view_state']);
    same($note['id'], $result->structuredContent->attachment->note_id);
    $download = decoded(download_call(array('issue_id' => 96,
        'attachment_id' => $result->structuredContent->attachment->attachment_id), 'bob-key'))->result;
    same(false, $download->isError);
    same(upload_arguments()->content_base64, $download->structuredContent->content_base64);
    same($before, staging_files());
});

test_case('preserves private and default note visibility with private attachment readback', function() {
    $GLOBALS['test_mantis']['writes'] = true;
    $GLOBALS['test_mantis']['config']['default_bugnote_view_status'] = 50;
    $result = new_note_file()->result;
    same(false, $result->isError);
    $noteId = $result->structuredContent->note->id;
    same(50, $GLOBALS['test_mantis']['notes'][$noteId]['view_state']);
    $args = array('issue_id' => 96, 'attachment_id' => $result->structuredContent->attachment->attachment_id);
    same(true, decoded(download_call($args, 'bob-key'))->result->isError);
    same(false, decoded(download_call($args))->result->isError);
});

test_case('attaches to an existing note while preserving its text, author and visibility', function() {
    $GLOBALS['test_mantis']['writes'] = true;
    $original = $GLOBALS['test_mantis']['notes'][1302];
    $before = staging_files();
    $result = decoded(upload_call(array('note_id' => 1302)))->result;
    same(false, $result->isError);
    same(1302, $result->structuredContent->note_id);
    same($original, $GLOBALS['test_mantis']['notes'][1302]);
    same(array(1302), $GLOBALS['test_mantis']['note_edits']);
    $download = decoded(download_call(array('attachment_id' => $result->structuredContent->attachment_id)))->result;
    same(false, $download->isError);
    same(upload_arguments()->content_base64, $download->structuredContent->content_base64);
    same($before, staging_files());
});

test_case('checks existing note membership, private visibility and separate owner edit thresholds', function() {
    $GLOBALS['test_mantis']['writes'] = true;
    same(true, decoded(upload_call(array('note_id' => 1303)))->result->isError);
    same(true, decoded(upload_call(array('issue_id' => 96, 'note_id' => 1303), 'bob-key'))->result->isError);
    same(true, decoded(upload_call(array('note_id' => 999999)))->result->isError);
    $GLOBALS['test_mantis']['config']['bugnote_user_edit_threshold'] = 60;
    same(true, decoded(upload_call(array('note_id' => 1302)))->result->isError);
    $GLOBALS['test_mantis']['config']['bugnote_user_edit_threshold'] = 25;
    $GLOBALS['test_mantis']['notes'][1302]['reporter_id'] = 2;
    $GLOBALS['test_mantis']['config']['update_bugnote_threshold'] = 60;
    same(true, decoded(upload_call(array('note_id' => 1302)))->result->isError);
    same(300, $GLOBALS['test_mantis']['last_file_id']);
});

test_case('keeps new-note native add/private permissions and moderation checks with staging cleanup', function() {
    $GLOBALS['test_mantis']['writes'] = true;
    $before = staging_files();
    $GLOBALS['test_mantis']['config']['add_bugnote_threshold'] = 60;
    same(true, new_note_file()->result->isError);
    $GLOBALS['test_mantis']['config']['add_bugnote_threshold'] = 25;
    $GLOBALS['test_mantis']['config']['set_view_status_threshold'] = 60;
    same(true, new_note_file(array('private' => true))->result->isError);
    $GLOBALS['test_mantis']['config']['set_view_status_threshold'] = 25;
    $GLOBALS['test_mantis']['moderation']['EVENT_BUGNOTE_ADD_MODERATE_CHECK'] = true;
    $native = file_get_contents(getenv('MANTISBT_SOURCE') . '/core/commands/IssueNoteAddCommand.php');
    $hasModeration = strpos($native, 'EVENT_BUGNOTE_ADD_MODERATE_CHECK') !== false;
    same($hasModeration, new_note_file()->result->isError);
    same($hasModeration ? 2000 : 2001, $GLOBALS['test_mantis']['last_note_id']);
    same($hasModeration ? 300 : 301, $GLOBALS['test_mantis']['last_file_id']);
    same($before, staging_files());
});

test_case('does not silently upload at issue level when note persistence fails', function() {
    $GLOBALS['test_mantis']['writes'] = true;
    $before = staging_files();
    $GLOBALS['test_mantis']['storage_failure'] = 'before_insert';
    same(true, new_note_file()->result->isError);
    same(2001, $GLOBALS['test_mantis']['last_note_id']);
    same(300, $GLOBALS['test_mantis']['last_file_id']);
    same($before, staging_files());
});

test_case('scopes upload settings to the issue project and restores the previous context on failure', function() {
    $GLOBALS['test_mantis']['writes'] = true;
    $GLOBALS['g_project_override'] = 999;
    $GLOBALS['test_mantis']['project_configs'][1] = array('max_file_size' => 1024);
    same(true, decoded(upload_call())->result->isError);
    same(999, $GLOBALS['g_project_override']);
    same(300, $GLOBALS['test_mantis']['last_file_id']);
});
