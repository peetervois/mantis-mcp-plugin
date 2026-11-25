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

