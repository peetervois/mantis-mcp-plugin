<?php
/** SPDX-License-Identifier: BSD-3-Clause */

test_case('reads the native Working to Resolved to Testing graph without changing status', function() {
    $workflow = management_call('mantis_get_workflow', array('issue_id' => 94))->result->structuredContent;
    same(60, $workflow->current_status_id);
    $edges = array_column($workflow->transitions, 'to_status_ids', 'from_status_id');
    check(in_array(70, $edges[60], true));
    check(!in_array(80, $edges[60], true));
    check(in_array(80, $edges[70], true));
    check(!in_array(80, $workflow->workflow_and_status_access_next_ids, true));
    check(in_array(70, $workflow->workflow_and_status_access_next_ids, true));
    same(1, count($GLOBALS['test_mantis']['calls']));
    same('issues', $GLOBALS['test_mantis']['calls'][0]['name']);
});

test_case('reports native status thresholds separately from workflow edges and preserves project context', function() {
    $GLOBALS['test_mantis']['project_configs'][1] = array('set_status_threshold' => array(70 => 60));
    $GLOBALS['g_project_override'] = 999;
    $workflow = management_call('mantis_get_workflow', array('issue_id' => 94))->result->structuredContent;
    same(999, $GLOBALS['g_project_override']);
    $statuses = array_column($workflow->statuses, null, 'id');
    same(false, $statuses[70]->current_user_has_status_access);
    check(!in_array(70, $workflow->workflow_and_status_access_next_ids, true));
    $edges = array_column($workflow->transitions, 'to_status_ids', 'from_status_id');
    check(in_array(70, $edges[60], true));
});

test_case('handles native default and incomplete workflows and rejects inaccessible issue inspection', function() {
    $GLOBALS['test_mantis']['config']['status_enum_workflow'] = array();
    $workflow = management_call('mantis_get_workflow', array('issue_id' => 94))->result->structuredContent;
    same(8, count($workflow->workflow_and_status_access_next_ids));
    $GLOBALS['test_mantis']['config']['status_enum_workflow'] = array(10 => '50:assigned');
    same(8, count(management_call('mantis_get_workflow', array('issue_id' => 94))->result
        ->structuredContent->workflow_and_status_access_next_ids));
    same(true, management_call('mantis_get_workflow', array('issue_id' => 94), 'bob-key')->result->isError);
});

test_case('bounds unusually large workflows and restores context even on failure', function() {
    $GLOBALS['test_mantis']['config']['status_enum_string'] = implode(',', array_map(function($id) { return $id . ':status' . $id; }, range(1, 201)));
    $GLOBALS['g_project_override'] = 999;
    same(true, management_call('mantis_get_workflow', array('issue_id' => 94))->result->isError);
    same(999, $GLOBALS['g_project_override']);
});

test_case('failed native REST status updates preserve the error and direct readback then workflow inspection', function() {
    $GLOBALS['test_mantis']['writes'] = true;
    $result = management_call('mantis_update_issue', array('issue_id' => 95,
        'changes' => (object) array('status_id' => 70)), 'bob-key')->result;
    same(true, $result->isError);
    check(strpos($result->content[0]->text, 'Your MantisBT account is not allowed') !== false);
    check(strpos($result->content[0]->text, 'mantis_get_workflow') !== false);
    check(strpos($result->content[0]->text, 'read-only/reopen') !== false);
    $d = $result->structuredContent;
    same('STATUS_CHANGE_NOT_CONFIRMED', $d->error_code);
    same('mantis_get_issue', $d->workflow_hint->next_reads[0]->tool);
    same('mantis_get_workflow', $d->workflow_hint->next_reads[1]->tool);
    same(95, $d->workflow_hint->next_reads[1]->arguments->issue_id);
    same(false, $d->workflow_hint->retry_automatically);
    same(true, $d->workflow_hint->native_validation_still_applies);
    same(array('update'), array_column($GLOBALS['test_mantis']['calls'], 'name'));
});

test_case('workflow hints cover unconfirmed internal failures without exposing exception text or issue state', function() {
    $p = new \Tauria\MantisMcp\Protocol(new \Tauria\MantisMcp\Tools(true), function() {
        throw new RuntimeException('private internal diagnostic value');
    });
    $response = $p->handle(tool_call('mantis_update_issue', (object) array('issue_id' => 123,
        'changes' => (object) array('status_id' => 80))));
    same(true, $response['result']['isError']);
    check(strpos(json_encode($response), 'private internal diagnostic value') === false);
    $hint = $response['result']['structuredContent']->workflow_hint;
    same(123, $hint->next_reads[0]->arguments->issue_id);
    same(array(), $GLOBALS['test_mantis']['calls']);
    same(array('next_reads', 'retry_automatically', 'native_validation_still_applies'), array_keys((array) $hint));
});

test_case('successful updates and status-free failures have no workflow hint', function() {
    $GLOBALS['test_mantis']['writes'] = true;
    $ok = management_call('mantis_update_issue', array('issue_id' => 94,
        'changes' => (object) array('status_id' => 50)))->result;
    same(false, $ok->isError);
    check(!isset($ok->structuredContent->workflow_hint));
    $failed = management_call('mantis_update_issue', array('issue_id' => 95,
        'changes' => (object) array('summary' => 'A title')), 'bob-key')->result;
    same(true, $failed->isError);
    check(!isset($failed->structuredContent));
    check(strpos($failed->content[0]->text, 'mantis_get_workflow') === false);
});

test_case('workflow hints do not weaken schema validation or add state to inaccessible read errors', function() {
    $GLOBALS['test_mantis']['writes'] = true;
    $bad = management_call('mantis_update_issue', array('issue_id' => 94,
        'changes' => (object) array('status_id' => 'closed')));
    same(-32602, $bad->error->code);
    check(!isset($bad->result));
    $denied = management_call('mantis_get_workflow', array('issue_id' => 95))->result;
    same(true, $denied->isError);
    check(!isset($denied->structuredContent));
    check(strpos($denied->content[0]->text, 'mantis_get_workflow') === false);
});

