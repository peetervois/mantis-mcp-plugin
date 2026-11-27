<?php
/** SPDX-License-Identifier: BSD-3-Clause */
namespace Tauria\MantisMcp;

final class Relationships {
    public static function types() {
        require_api('relationship_api.php');
        global $g_relationships;
        $types = array();
        foreach ($g_relationships as $id => $definition) {
            if (!is_numeric($id) || (int) $id < 0) { continue; }
            $types[] = array('id' => (int) $id, 'name' => relationship_get_name_for_api($id),
                'label' => relationship_get_description_src_side($id),
                'complementary_type_id' => (int) relationship_get_complementary_type($id));
        }
        return (object) array('types' => $types);
    }

    public static function listForIssue($issueId) {
        require_api('relationship_api.php');
        $different = false;
        $visible = array();
        foreach (relationship_get_all($issueId, $different) as $row) {
            $target = (int) ($row->src_bug_id == $issueId ? $row->dest_bug_id : $row->src_bug_id);
            $project = bug_get_field($target, 'project_id');
            if (!access_has_bug_level(config_get('view_bug_threshold', null, null, $project), $target)
                || !access_has_bug_level(config_get('webservice_readonly_access_level_threshold', null, null, $project), $target)) {
                continue;
            }
            $type = (int) ($row->src_bug_id == $issueId
                ? $row->type : relationship_get_complementary_type($row->type));
            $visible[] = array('id' => (int) $row->id, 'target_issue_id' => $target,
                'type' => array('id' => $type, 'name' => relationship_get_name_for_api($type),
                    'label' => relationship_get_description_src_side($type)));
        }
        return (object) array('issue_id' => $issueId, 'relationships' => $visible);
    }

    public static function target($issueId, $relationshipId) {
        foreach (self::listForIssue($issueId)->relationships as $row) {
            if ($row['id'] === $relationshipId) { return $row['target_issue_id']; }
        }
        throw new ToolFailure('The relationship does not exist or is not accessible for this issue.');
    }

    public static function set($issueId, $targetId, $typeId) {
        return ProjectContext::run($issueId, function() use ($issueId, $targetId, $typeId) {
            return self::writeRelationship($issueId, $targetId, $typeId);
        });
    }

    private static function writeRelationship($issueId, $targetId, $typeId) {
        require_api('commands/IssueRelationshipAddCommand.php');
        $command = new \IssueRelationshipAddCommand(array('query' => array('issue_id' => $issueId),
            'payload' => array('issue' => array('id' => $targetId), 'type' => array('id' => $typeId))));
        $result = $command->execute();
        self::clearCache($issueId, $targetId);
        foreach (self::listForIssue($issueId)->relationships as $row) {
            if ($row['id'] === (int) $result['id'] && $row['target_issue_id'] === $targetId
                && $row['type']['id'] === $typeId) {
                return (object) array('issue_id' => $issueId, 'relationship' => $row);
            }
        }
        throw new ToolFailure('Relationship write could not be verified. Read the issue before retrying.');
    }

    public static function delete($issueId, $relationshipId) {
        return ProjectContext::run($issueId, function() use ($issueId, $relationshipId) {
            return self::removeRelationship($issueId, $relationshipId);
        });
    }

    private static function removeRelationship($issueId, $relationshipId) {
        $targetId = self::target($issueId, $relationshipId);
        require_api('commands/IssueRelationshipDeleteCommand.php');
        $command = new \IssueRelationshipDeleteCommand(array('query' => array(
            'issue_id' => $issueId, 'relationship_id' => $relationshipId)));
        $command->execute();
        self::clearCache($issueId, $targetId);
        foreach (self::listForIssue($issueId)->relationships as $row) {
            if ($row['id'] === $relationshipId) {
                throw new ToolFailure('Relationship deletion could not be verified. Read the issue before retrying.');
            }
        }
        return (object) array('issue_id' => $issueId, 'relationship_id' => $relationshipId, 'deleted' => true);
    }

    private static function clearCache($issueId, $targetId) {
        // Native relationship mutations do not invalidate the 2.29 request caches.
        foreach (array($issueId, $targetId) as $id) {
            unset($GLOBALS['g_cache_relationships_src'][$id], $GLOBALS['g_cache_relationships_dest'][$id]);
        }
    }

}
