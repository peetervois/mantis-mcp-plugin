<?php
/** SPDX-License-Identifier: BSD-3-Clause */

test_case('discovers native type zero and complementary relationship direction', function() {
    $types = management_call('mantis_get_relationship_types')->result->structuredContent->types;
    $map = array_column($types, null, 'id');
    same('duplicate-of', $map[0]->name);
    same(4, $map[0]->complementary_type_id);
    same(3, $map[2]->complementary_type_id);
});

test_case('verifies create, replace and delete using native cached relationship readers', function() {
    $GLOBALS['test_mantis']['writes'] = true;
    foreach (array(94, 96) as $id) {
        same(array(), management_call('mantis_get_relationships', array('issue_id' => $id))->result->structuredContent->relationships);
    }
    $created = management_call('mantis_set_relationship', array('issue_id' => 94, 'target_issue_id' => 96, 'type_id' => 2))->result;
    same(false, $created->isError);
    $id = $created->structuredContent->relationship->id;
    same(3, management_call('mantis_get_relationships', array('issue_id' => 96))->result->structuredContent->relationships[0]->type->id);
    $changed = management_call('mantis_set_relationship', array('issue_id' => 94, 'target_issue_id' => 96, 'type_id' => 0))->result;
    same(false, $changed->isError);
    same($id, $changed->structuredContent->relationship->id);
    same(1, count($GLOBALS['test_mantis']['relationships']));
    same(4, management_call('mantis_get_relationships', array('issue_id' => 96))->result->structuredContent->relationships[0]->type->id);
    $deleted = management_call('mantis_delete_relationship', array('issue_id' => 94, 'relationship_id' => $id))->result;
    same(false, $deleted->isError);
    same(true, $deleted->structuredContent->deleted);
    foreach (array(94, 96) as $issue) {
        same(array(), management_call('mantis_get_relationships', array('issue_id' => $issue))->result->structuredContent->relationships);
    }
});

test_case('verifies deletion through the destination with both endpoint caches already populated', function() {
    $GLOBALS['test_mantis']['writes'] = true;
    $id = relationship_upsert(94, 96, 2);
    foreach (array(94, 96) as $issue) {
        same(1, count(management_call('mantis_get_relationships', array('issue_id' => $issue))->result->structuredContent->relationships));
    }
    $deleted = management_call('mantis_delete_relationship', array('issue_id' => 96, 'relationship_id' => $id))->result;
    same(false, $deleted->isError);
    same(array(), management_call('mantis_get_relationships', array('issue_id' => 94))->result->structuredContent->relationships);
    same(array(), management_call('mantis_get_relationships', array('issue_id' => 96))->result->structuredContent->relationships);
});

test_case('does not disclose hidden peers or mutate links without both endpoint permissions', function() {
    $GLOBALS['test_mantis']['writes'] = true;
    relationship_upsert(96, 95, 1);
    same(array(), management_call('mantis_get_relationships', array('issue_id' => 96))->result->structuredContent->relationships);
    same(true, management_call('mantis_set_relationship', array('issue_id' => 94, 'target_issue_id' => 95, 'type_id' => 1))->result->isError);
    same(true, management_call('mantis_delete_relationship', array('issue_id' => 96, 'relationship_id' => 401))->result->isError);
    same(true, management_call('mantis_delete_relationship', array('issue_id' => 94, 'relationship_id' => 401))->result->isError);
    same(1, count($GLOBALS['test_mantis']['relationships']));
});

test_case('keeps native relationship self-link, invalid-type, update threshold and readonly validation', function() {
    $GLOBALS['test_mantis']['writes'] = true;
    foreach (array(array('target_issue_id' => 94, 'type_id' => 1), array('target_issue_id' => 96, 'type_id' => 999)) as $extra) {
        same(true, management_call('mantis_set_relationship', array('issue_id' => 94) + $extra)->result->isError);
    }
    $GLOBALS['test_mantis']['config']['update_bug_threshold'] = 60;
    same(true, management_call('mantis_set_relationship', array('issue_id' => 94, 'target_issue_id' => 96, 'type_id' => 1))->result->isError);
    $GLOBALS['test_mantis']['config']['update_bug_threshold'] = 25;
    $GLOBALS['test_mantis']['readonly'] = true;
    same(true, management_call('mantis_set_relationship', array('issue_id' => 94, 'target_issue_id' => 96, 'type_id' => 1))->result->isError);
    same(array(), $GLOBALS['test_mantis']['relationships']);
});

test_case('includes custom native relationship definitions without hardcoded type enums', function() {
    global $g_relationships;
    $original = $g_relationships;
    try {
        $g_relationships[99] = array('#name' => 'custom-link', '#description' => 'custom_link', '#complementary' => 99);
        $types = management_call('mantis_get_relationship_types')->result->structuredContent->types;
        $map = array_column($types, null, 'id');
        same('custom-link', $map[99]->name);
        same(99, $map[99]->complementary_type_id);
    } finally { $g_relationships = $original; }
});

