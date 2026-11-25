<?php
/** SPDX-License-Identifier: BSD-3-Clause */

test_case('uses actual native relationship commands for create, replace and reverse-direction deletion', function() {
    $GLOBALS['test_mantis']['writes'] = true;
    $first = management_call('mantis_set_relationship', array('issue_id' => 94, 'target_issue_id' => 96, 'type_id' => 2))->result;
    same(false, $first->isError);
    $id = $first->structuredContent->relationship->id;
    same(2, $first->structuredContent->relationship->type->id);
    $reverse = management_call('mantis_get_relationships', array('issue_id' => 96))->result->structuredContent->relationships;
    same(3, $reverse[0]->type->id);
    $updated = management_call('mantis_set_relationship', array('issue_id' => 94, 'target_issue_id' => 96, 'type_id' => 0))->result;
    same(false, $updated->isError);
    same($id, $updated->structuredContent->relationship->id);
    same(1, count($GLOBALS['test_mantis']['relationships']));
    $deleted = management_call('mantis_delete_relationship', array('issue_id' => 96, 'relationship_id' => $id))->result;
    same(false, $deleted->isError);
    same(true, $deleted->structuredContent->deleted);
    same(array(), $GLOBALS['test_mantis']['relationships']);
});

test_case('deletes an uploaded attachment through the native file API and verifies absence', function() {
    $GLOBALS['test_mantis']['writes'] = true;
    $file = decoded(upload_call())->result->structuredContent;
    $result = management_call('mantis_delete_attachment', array('issue_id' => 94, 'attachment_id' => $file->attachment_id))->result;
    same(false, $result->isError);
    same(true, $result->structuredContent->deleted);
    check(!isset($GLOBALS['test_mantis']['attachments'][$file->attachment_id]));
    same(true, management_call('mantis_delete_attachment', array('issue_id' => 94, 'attachment_id' => $file->attachment_id))->result->isError);
});

test_case('deletion checks issue ownership, readonly and native delete thresholds independently of download', function() {
    $GLOBALS['test_mantis']['writes'] = true;
    $file = decoded(upload_call())->result->structuredContent;
    $args = array('issue_id' => 96, 'attachment_id' => $file->attachment_id);
    same(true, management_call('mantis_delete_attachment', $args)->result->isError);
    $args['issue_id'] = 94;
    $GLOBALS['test_mantis']['delete_threshold'] = 60;
    same(true, management_call('mantis_delete_attachment', $args)->result->isError);
    $GLOBALS['test_mantis']['delete_own'] = true;
    $GLOBALS['test_mantis']['readonly'] = true;
    same(true, management_call('mantis_delete_attachment', $args)->result->isError);
    $GLOBALS['test_mantis']['readonly'] = false;
    $GLOBALS['test_mantis']['download_threshold'] = 60;
    same(false, management_call('mantis_delete_attachment', $args)->result->isError);
});

test_case('never lets attachment deletion bypass private note visibility', function() {
    $GLOBALS['test_mantis']['writes'] = true;
    $GLOBALS['test_mantis']['delete_threshold'] = 60;
    $GLOBALS['test_mantis']['delete_own'] = true;
    $GLOBALS['test_mantis']['attachments'][220]['user_id'] = 2;
    same(true, management_call('mantis_delete_attachment', array('issue_id' => 96, 'attachment_id' => 220), 'bob-key')->result->isError);
    check(isset($GLOBALS['test_mantis']['attachments'][220]));
});

test_case('deletes native disk storage rather than only removing attachment metadata', function() {
    $GLOBALS['test_mantis']['writes'] = true;
    $directory = sys_get_temp_dir() . '/mantismcp-test-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $GLOBALS['test_mantis']['storage_dir'] = $directory;
    $GLOBALS['test_mantis']['file_method'] = DISK;
    $file = decoded(upload_call())->result->structuredContent;
    $row = $GLOBALS['test_mantis']['attachments'][$file->attachment_id];
    $path = $directory . '/' . $row['diskfile'];
    check(is_file($path));
    $result = management_call('mantis_delete_attachment', array('issue_id' => 94, 'attachment_id' => $file->attachment_id))->result;
    same(false, $result->isError);
    check(!is_file($path));
});

