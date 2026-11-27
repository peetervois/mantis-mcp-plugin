<?php
/** SPDX-License-Identifier: BSD-3-Clause */
namespace Tauria\MantisMcp;

final class ProjectContext {
    public static function run($issueId, callable $operation) {
        require_api('bug_api.php');
        $present = array_key_exists('g_project_override', $GLOBALS);
        $previous = $present ? $GLOBALS['g_project_override'] : null;
        $GLOBALS['g_project_override'] = (int) bug_get_field($issueId, 'project_id');
        try { return $operation(); }
        finally {
            if ($present) { $GLOBALS['g_project_override'] = $previous; }
            else { unset($GLOBALS['g_project_override']); }
        }
    }

}
