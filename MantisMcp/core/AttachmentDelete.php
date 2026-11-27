<?php
/** SPDX-License-Identifier: BSD-3-Clause */
namespace Tauria\MantisMcp;

final class AttachmentDelete {
    public static function delete($issueId, $attachmentId) {
        return ProjectContext::run($issueId, function() use ($issueId, $attachmentId) {
            return self::removeAttachment($issueId, $attachmentId);
        });
    }

    private static function removeAttachment($issueId, $attachmentId) {
        $info = AttachmentDownload::authorize($issueId, $attachmentId, PHP_INT_MAX, false);
        if (!file_can_delete_bug_attachments($issueId, $info['uploader_id'])) {
            throw new ToolFailure('Your MantisBT account cannot delete this attachment.');
        }
        // Newer Mantis has a dedicated command; older releases use this native file API.
        $commandFile = config_get_global('core_path') . 'commands/IssueFileDeleteCommand.php';
        if (is_file($commandFile)) {
            require_once $commandFile;
            $command = new \IssueFileDeleteCommand(array('query' => array(
                'issue_id' => $issueId, 'file_id' => $attachmentId)));
            $command->execute();
        } else {
            file_delete($attachmentId, 'bug', $info['note_id']);
        }
        bug_attachments_clear_cache($issueId);
        foreach (bug_get_attachments($issueId) as $row) {
            if ((int) $row['id'] === $attachmentId) {
                throw new ToolFailure('Attachment deletion could not be verified. Read the issue before retrying.');
            }
        }
        return (object) array('issue_id' => $issueId, 'attachment_id' => $attachmentId, 'deleted' => true);
    }
}
