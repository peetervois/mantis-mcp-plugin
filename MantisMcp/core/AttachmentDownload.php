<?php
/**
 * SPDX-License-Identifier: BSD-3-Clause
 * Copyright (c) 2026, Peeter Vois
 */

namespace Tauria\MantisMcp;

/** Original bytes travel only in structuredContent, in bounded base64 chunks. */
final class DownloadChunk {
    private $data;

    public function __construct(array $data) {
        $this->data = (object) $data;
    }

    public function result() {
        $summary = (array) $this->data;
        unset($summary['content_base64']);
        return array(
            'content' => array(array('type' => 'text', 'text' => json_encode($summary,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))),
            'structuredContent' => $this->data,
            'isError' => false,
        );
    }
}

final class AttachmentDownload {
    const CHUNK_BYTES = 65536;
    const DEFAULT_MAX_BYTES = false;

    public static function limit($configured) {
        if ($configured === false || $configured === null) { return null; }
        $limit = filter_var($configured, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1)));
        if ($limit === false) { throw new ToolFailure('Download limit must be unset or a positive integer byte count.'); }
        return $limit;
    }

    /** Call only after the issue's native REST access check has succeeded. */
    public static function authorize($issueId, $attachmentId, $maxBytes, $requireDownload = true) {
        require_api('file_api.php');
        require_api('access_api.php');
        foreach (bug_get_attachments($issueId) as $row) {
            if ((int) $row['id'] !== $attachmentId) {
                continue;
            }
            $owner = (int) $row['user_id'];
            $noteId = (int) $row['bugnote_id'];
            if (!file_can_view_bug_attachments($issueId, $owner)
                || ($requireDownload && !file_can_download_bug_attachments($issueId, $owner))) {
                break;
            }
            if ($noteId > 0) {
                $projectId = bug_get_field($issueId, 'project_id');
                if ((int) bugnote_get_field($noteId, 'bug_id') !== $issueId
                    || !access_has_bugnote_level(config_get('view_bug_threshold', null, null, $projectId), $noteId)
                    || !file_can_view_bugnote_attachments($noteId, $owner, $issueId)
                    || ($requireDownload && !file_can_download_bugnote_attachments($noteId, $owner))) {
                    break;
                }
            }
            $size = (int) $row['filesize'];
            if ($size < 0 || ($maxBytes !== null && $size > $maxBytes)) {
                throw new ToolFailure('Attachment exceeds the configured download size limit.');
            }
            return array('note_id' => $noteId, 'size' => $size, 'uploader_id' => $owner,
                'filename' => file_get_display_name($row['filename']));
        }
        throw new ToolFailure('The attachment does not exist or is not accessible to your MantisBT account.');
    }

    /** Validate the native response before returning any bytes to the client. */
    public static function chunk($issueId, $attachmentId, array $info, $response,
        $offset, $length, $expectedHash, $maxBytes) {
        if (!$response instanceof \stdClass || !isset($response->files)
            || !is_array($response->files) || count($response->files) !== 1) {
            throw new ToolFailure('MantisBT did not return the requested attachment.');
        }
        $file = $response->files[0];
        if (!$file instanceof \stdClass || !isset($file->id, $file->filename, $file->size, $file->content)
            || (int) $file->id !== $attachmentId || $file->filename !== $info['filename']
            || (int) $file->size !== $info['size'] || !is_string($file->content)) {
            throw new ToolFailure('The attachment changed or MantisBT returned an unexpected response.');
        }
        $filename = $file->filename;
        if (!is_string($filename) || $filename === '' || strlen($filename) > 1024
            || $filename === '.' || $filename === '..' || preg_match('~[\\\\/\x00-\x1f\x7f]~', $filename)) {
            throw new ToolFailure('The attachment has an unsupported filename.');
        }
        if (strlen($file->content) > 4 * (int) ceil($info['size'] / 3)) {
            throw new ToolFailure('MantisBT returned content exceeding the attachment metadata size.');
        }
        $bytes = base64_decode($file->content, true);
        if ($bytes === false || base64_encode($bytes) !== $file->content
            || strlen($bytes) !== $info['size'] || ($maxBytes !== null && strlen($bytes) > $maxBytes)) {
            throw new ToolFailure('MantisBT returned invalid or incomplete attachment content.');
        }
        $hash = hash('sha256', $bytes);
        if ($expectedHash !== null && !hash_equals($expectedHash, $hash)) {
            throw new ToolFailure('Attachment content changed. Discard partial bytes and restart the download.');
        }
        $mime = isset($file->content_type) && is_string($file->content_type)
            ? strtolower(trim(explode(';', $file->content_type)[0])) : '';
        if (!preg_match('~\A[a-z0-9!#$&^_.+\-]+/[a-z0-9!#$&^_.+\-]+\z~D', $mime)) {
            $mime = 'application/octet-stream';
        }
        $chunk = substr($bytes, $offset, $length);
        $next = $offset + strlen($chunk);
        return new DownloadChunk(array(
            'issue_id' => $issueId, 'attachment_id' => $attachmentId,
            'note_id' => $info['note_id'], 'filename' => $filename, 'mime_type' => $mime,
            'size' => strlen($bytes), 'sha256' => $hash, 'offset' => $offset,
            'bytes_returned' => strlen($chunk), 'next_offset' => $next < strlen($bytes) ? $next : null,
            'complete' => $next === strlen($bytes), 'content_base64' => base64_encode($chunk),
        ));
    }
}
