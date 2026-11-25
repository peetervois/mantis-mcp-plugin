<?php
/**
 * SPDX-License-Identifier: BSD-3-Clause
 * Copyright (c) 2026, Peeter Vois
 */

namespace Tauria\MantisMcp;

/** One bounded file, using the same command and storage APIs as native REST. */
final class AttachmentUpload {
    const DEFAULT_MAX_BYTES = 524288;
    const HARD_MAX_BYTES = 5242880;
    const MAX_ENCODED_BYTES = 6990508;

    public static function limit($configured) {
        return max(1024, min(self::HARD_MAX_BYTES, (int) $configured));
    }

    /** Call only after the native REST issue-read access check succeeds. */
    public static function execute($issueId, array $arguments, $maxBytes) {
        require_api('file_api.php');
        require_api('bug_api.php');
        require_api('commands/IssueFileAddCommand.php');
        if (!class_exists('IssueFileAddCommand')) {
            throw new ToolFailure('The native MantisBT attachment upload command is unavailable.');
        }
        if (bug_is_readonly($issueId) || !file_allow_bug_upload($issueId)) {
            throw new ToolFailure('Your MantisBT account cannot upload attachments to this issue.');
        }
        $name = $arguments['filename'];
        if ($name === '.' || $name === '..' || strlen($name) > 250
            || preg_match('~[\\\\/:\x00-\x1f\x7f]~', $name)) {
            throw new ToolFailure('The attachment filename is unsafe or exceeds 250 UTF-8 bytes.');
        }
        if (!file_type_check($name)) {
            throw new ToolFailure('This attachment filename is disallowed by MantisBT file-type rules.');
        }
        $maxBytes = min(self::limit($maxBytes), (int) config_get('max_file_size'),
            (int) ini_get_number('upload_max_filesize'), (int) ini_get_number('post_max_size'));
        $encoded = $arguments['content_base64'];
        if (strlen($encoded) > 4 * (int) ceil(max(0, $maxBytes) / 3)) {
            throw new ToolFailure('Attachment exceeds the configured upload size limit.');
        }
        $bytes = base64_decode($encoded, true);
        if ($bytes === false || base64_encode($bytes) !== $encoded || $bytes === '') {
            throw new ToolFailure('Attachment content must be nonempty canonical base64.');
        }
        if (strlen($bytes) > $maxBytes) {
            throw new ToolFailure('Attachment exceeds the configured upload size limit.');
        }
        $hash = hash('sha256', $bytes);
        if (!hash_equals($arguments['sha256'], $hash)) {
            throw new ToolFailure('Attachment SHA-256 does not match the supplied bytes.');
        }
        $before = array_column(bug_get_attachments($issueId), 'id');
        $temporary = null;
        $started = false;
        try {
            // tempnam creates a private file exclusively; callers never choose its path.
            $temporary = tempnam(sys_get_temp_dir(), 'mantismcp-');
            if ($temporary === false || !chmod($temporary, 0600)
                || file_put_contents($temporary, $bytes) !== strlen($bytes)) {
                throw new ToolFailure('Could not stage the attachment; nothing was uploaded.');
            }
            $command = new \IssueFileAddCommand(array(
                'query' => array('issue_id' => $issueId),
                'payload' => array('files' => array(array(
                    'name' => $name, 'type' => strtolower($arguments['mime_type']),
                    'tmp_name' => $temporary, 'size' => strlen($bytes),
                    'error' => UPLOAD_ERR_OK, 'browser_upload' => false,
                ))),
            ));
            $started = true;
            $command->execute();
            // Native file_add does not invalidate this request-local attachment cache.
            bug_attachments_clear_cache($issueId);
            $matches = array();
            foreach (bug_get_attachments($issueId) as $row) {
                if (in_array($row['id'], $before) || (int) $row['user_id'] !== (int) auth_get_current_user_id()
                    || (int) $row['bugnote_id'] !== 0 || (int) $row['filesize'] !== strlen($bytes)) {
                    continue;
                }
                $stored = file_get_content((int) $row['id']);
                if (!is_array($stored) || !isset($stored['content'], $stored['type'])
                    || !hash_equals($hash, hash('sha256', $stored['content']))) {
                    continue;
                }
                $matches[] = (object) array(
                    'issue_id' => $issueId, 'attachment_id' => (int) $row['id'], 'note_id' => 0,
                    'filename' => file_get_display_name($row['filename']),
                    'mime_type' => strtolower(trim(explode(';', $stored['type'])[0])),
                    'size' => strlen($stored['content']), 'sha256' => $hash,
                    'reporter_id' => (int) $row['user_id'],
                );
            }
            if (count($matches) !== 1) {
                throw new ToolFailure('Upload could not be uniquely verified. Read the issue before retrying; it may have completed.');
            }
            return $matches[0];
        } catch (\Throwable $error) {
            if ($error instanceof ToolFailure) {
                throw $error;
            }
            throw new ToolFailure($started
                ? 'Upload could not be confirmed. Read the issue before retrying; it may have completed.'
                : 'Could not stage the attachment; nothing was uploaded.');
        } finally {
            if (is_string($temporary) && is_file($temporary) && !unlink($temporary)) {
                error_log('MantisMcp: attachment staging cleanup failed.');
            }
        }
    }
}
