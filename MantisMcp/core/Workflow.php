<?php
/** SPDX-License-Identifier: BSD-3-Clause */
namespace Tauria\MantisMcp;

final class Workflow {
    public static function inspect($issueId) {
        require_api('bug_api.php');
        require_api('access_api.php');
        $issue = bug_get($issueId, true);
        $project = (int) $issue->project_id;
        $hadOverride = array_key_exists('g_project_override', $GLOBALS);
        $previous = $hadOverride ? $GLOBALS['g_project_override'] : null;
        $GLOBALS['g_project_override'] = $project;
        try {
            $names = \MantisEnum::getAssocArrayIndexedByValues(config_get('status_enum_string'));
            if (count($names) > 200) {
                throw new ToolFailure('The configured workflow exceeds the supported status count.');
            }
            $statuses = array();
            $transitions = array();
            $next = array();
            foreach ($names as $id => $name) {
                $threshold = access_get_status_threshold($id, $project);
                $statuses[] = array('id' => $id, 'name' => $name,
                    'label' => get_enum_element('status', $id),
                    'required_access_level' => $threshold,
                    'current_user_has_status_access' => access_has_bug_level($threshold, $issueId));
                $targets = array();
                foreach ($names as $target => $unused) {
                    if ($id !== $target && bug_check_workflow($id, $target)) {
                        $targets[] = $target;
                    }
                }
                $transitions[] = array('from_status_id' => $id, 'to_status_ids' => $targets);
                if ($id !== (int) $issue->status && bug_check_workflow($issue->status, $id)
                    && access_has_bug_level($threshold, $issueId)) {
                    $next[] = $id;
                }
            }
            return (object) array('issue_id' => $issueId, 'project_id' => $project,
                'current_status_id' => (int) $issue->status,
                'is_readonly' => bug_is_readonly($issueId),
                'current_user_can_update_issue_fields' => access_has_bug_level(config_get('update_bug_threshold'), $issueId),
                'statuses' => $statuses, 'transitions' => $transitions,
                'workflow_and_status_access_next_ids' => $next,
                'checks' => 'Effective native workflow and status thresholds only. Native update rules, '
                    . 'read-only/reopen rules and required fields still apply; this does not change any status.');
        } finally {
            if ($hadOverride) { $GLOBALS['g_project_override'] = $previous; }
            else { unset($GLOBALS['g_project_override']); }
        }
    }

}
