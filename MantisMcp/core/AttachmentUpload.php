<?php
/**
 * SPDX-License-Identifier: BSD-3-Clause
 * Copyright (c) 2026, Peeter Vois
 */

namespace Tauria\MantisMcp;

/** One bounded file, using the same command and storage APIs as native REST. */
final class AttachmentUpload {
    const DEFAULT_MAX_BYTES = false;

    public static function inherits($configured) { return $configured === null || $configured === false; }

    public static function limit($configured, $nativeMax = null) {
        if (self::inherits($configured)) { return $nativeMax === null ? null : self::nativeLimit($nativeMax); }
        $limit = filter_var($configured, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1)));
        if ($limit === false) { throw new ToolFailure('MCP upload limit must inherit MantisBT or be a positive integer.'); }
        return $limit;
    }

    public static function effectiveLimit($configured, $nativeMax) {
        $native = self::nativeLimit($nativeMax);
        return min(self::limit($configured, $native), $native,
            (int) ini_get_number('upload_max_filesize'), (int) ini_get_number('post_max_size'));
    }

    private static function nativeLimit($value) {
        if (is_string($value) && preg_match('/\A[0-9]+\z/', $value)) { $value = ltrim($value, '0'); if ($value === '') { $value = '0'; } }
        $limit = filter_var($value, FILTER_VALIDATE_INT, array('options' => array('min_range' => 0)));
        if ($limit === false) { throw new ToolFailure('MantisBT has an invalid attachment size setting.'); }
        return $limit;
    }

    /** Call only after the native REST issue-read access check succeeds. */
    public static function execute($issueId, array $arguments, $maxBytes, ?array $newNote = null) {
        return ProjectContext::run($issueId, function() use ($issueId, $arguments, $maxBytes, $newNote) {
            return self::perform($issueId, $arguments, $maxBytes, $newNote);
        });
    }

    public static function authorize($issueId, array $arguments, $maxBytes, $size = null) {
        require_api('file_api.php');
        require_api('bug_api.php');
        require_api('commands/IssueFileAddCommand.php');
        if (!class_exists('IssueFileAddCommand')) {
            throw new ToolFailure('The native MantisBT attachment upload command is unavailable.');
        }
        if (bug_is_readonly($issueId) || !file_allow_bug_upload($issueId)) {
            throw new ToolFailure('Your MantisBT account cannot upload attachments to this issue.');
        }
        $noteId = isset($arguments['note_id']) ? $arguments['note_id'] : 0;
        if ($noteId > 0) {
            self::authorizeExistingNote($issueId, $noteId);
        }
        $name = $arguments['filename'];
        if ($name === '.' || $name === '..' || strlen($name) > 250
            || preg_match('~[\\\\/:\x00-\x1f\x7f]~', $name)) {
            throw new ToolFailure('The attachment filename is unsafe or exceeds 250 UTF-8 bytes.');
        }
        if (!file_type_check($name)) {
            throw new ToolFailure('This attachment filename is disallowed by MantisBT file-type rules.');
        }
        $maxBytes = self::effectiveLimit($maxBytes, config_get('max_file_size'));
        if ($size !== null && $size > $maxBytes) {
            throw new ToolFailure('Attachment exceeds the configured upload size limit.');
        }
        return $maxBytes;
    }

    private static function perform($issueId, array $arguments, $maxBytes, ?array $newNote) {
        $limit = self::authorize($issueId, $arguments, $maxBytes);
        $encoded = $arguments['content_base64'];
        if (strlen($encoded) > 4 * (int) ceil(max(0, $limit) / 3)) {
            throw new ToolFailure('Attachment exceeds the configured upload size limit.');
        }
        $bytes = base64_decode($encoded, true);
        if ($bytes === false || base64_encode($bytes) !== $encoded || $bytes === '') {
            throw new ToolFailure('Attachment content must be nonempty canonical base64.');
        }
        if (strlen($bytes) > $limit) {
            throw new ToolFailure('Attachment exceeds the configured upload size limit.');
        }
        $hash = hash('sha256', $bytes);
        if (!hash_equals($arguments['sha256'], $hash)) {
            throw new ToolFailure('Attachment SHA-256 does not match the supplied bytes.');
        }
        $temporary = null;
        try {
            // tempnam creates a private file exclusively; callers never choose its path.
            $temporary = tempnam(sys_get_temp_dir(), 'mantismcp-');
            if ($temporary === false || !chmod($temporary, 0600)
                || file_put_contents($temporary, $bytes) !== strlen($bytes)) {
                throw new ToolFailure('Could not stage the attachment; nothing was uploaded.');
            }
            return self::persist($issueId, $arguments, $maxBytes, $temporary, strlen($bytes), $hash, $newNote);
        } finally {
            if (is_string($temporary) && is_file($temporary) && !unlink($temporary)) {
                error_log('MantisMcp: attachment staging cleanup failed.');
            }
        }
    }

    /** Internal staged-file entry point; no MCP tool accepts filesystem paths. */
    public static function fromStaged($issueId, array $arguments, $maxBytes, $path, $size, $hash, ?array $newNote = null) {
        return ProjectContext::run($issueId, function() use ($issueId, $arguments, $maxBytes, $path, $size, $hash, $newNote) {
            self::authorize($issueId, $arguments, $maxBytes, $size);
            clearstatcache(true, $path);
            if (!is_file($path) || filesize($path) !== $size || !hash_equals($hash, hash_file('sha256', $path))) {
                throw new ToolFailure('Assembled attachment size or SHA-256 does not match the original.');
            }
            return self::persist($issueId, $arguments, $maxBytes, $path, $size, $hash, $newNote);
        });
    }

    private static function persist($issueId, array $arguments, $maxBytes, $path, $size, $hash, ?array $newNote) {
        self::authorize($issueId, $arguments, $maxBytes, $size);
        $noteId = isset($arguments['note_id']) ? $arguments['note_id'] : 0;
        $before = array_column(bug_get_attachments($issueId), 'id');
        try {
            $files = array(array('name' => $arguments['filename'], 'type' => strtolower($arguments['mime_type']),
                'tmp_name' => $path, 'size' => $size,
                'error' => UPLOAD_ERR_OK, 'browser_upload' => false));
            if ($newNote !== null) {
                require_api('commands/IssueNoteAddCommand.php');
                $command = new \IssueNoteAddCommand(array('query' => array('issue_id' => $issueId),
                    'payload' => $newNote + array('files' => $files)));
                $result = $command->execute();
                if (!isset($result['id']) || (int) $result['id'] < 1) {
                    throw new ToolFailure('Note creation could not be confirmed, possibly due to moderation. Read the issue before retrying.');
                }
                $noteId = (int) $result['id'];
            } elseif ($noteId > 0) {
                // Apply the same author/other-author edit thresholds as bugnote_update.php.
                self::authorizeExistingNote($issueId, $noteId);
                file_attach_files($issueId, $files, $noteId);
                bugnote_date_update($noteId);
                event_signal('EVENT_BUGNOTE_EDIT', array($issueId, $noteId));
            } else {
                $command = new \IssueFileAddCommand(array('query' => array('issue_id' => $issueId),
                    'payload' => array('files' => $files)));
                $command->execute();
            }
            // Native file_add does not invalidate this request-local attachment cache.
            bug_attachments_clear_cache($issueId);
            $matches = array();
            foreach (bug_get_attachments($issueId) as $row) {
                if (in_array($row['id'], $before) || (int) $row['user_id'] !== (int) auth_get_current_user_id()
                    || (int) $row['bugnote_id'] !== $noteId || (int) $row['filesize'] !== $size) {
                    continue;
                }
                $stored = file_get_content((int) $row['id']);
                if (!is_array($stored) || !isset($stored['content'], $stored['type'])
                    || !hash_equals($hash, hash('sha256', $stored['content']))) {
                    continue;
                }
                $matches[] = (object) array(
                    'issue_id' => $issueId, 'attachment_id' => (int) $row['id'], 'note_id' => $noteId,
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
            throw new ToolFailure('Upload could not be confirmed. Read the issue before retrying; it may have completed.');
        }
    }

    private static function authorizeExistingNote($issueId, $noteId) {
        require_api('bugnote_api.php');
        require_api('access_api.php');
        $project = bug_get_field($issueId, 'project_id');
        if (!bugnote_exists($noteId) || (int) bugnote_get_field($noteId, 'bug_id') !== $issueId
            || !access_has_bugnote_level(config_get('view_bug_threshold', null, null, $project), $noteId)) {
            throw new ToolFailure('The note does not exist or is not accessible for this issue.');
        }
        $own = (int) bugnote_get_field($noteId, 'reporter_id') === (int) auth_get_current_user_id();
        $threshold = config_get($own ? 'bugnote_user_edit_threshold' : 'update_bugnote_threshold', null, null, $project);
        if (!access_has_bugnote_level($threshold, $noteId)) {
            throw new ToolFailure('Your MantisBT account cannot edit this note.');
        }
    }
}
