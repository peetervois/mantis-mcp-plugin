<?php
/** SPDX-License-Identifier: BSD-3-Clause */
namespace Tauria\MantisMcp;

/** Private per-owner staging; only finalization can create a Mantis attachment. */
final class UploadSessions {
    const CHUNK_BYTES = 262144;
    const IDLE_SECONDS = 86400;
    const MAX_STATE_BYTES = 2097152;

    public static function chunkLimit($requestBytes) {
        $budget = Http::requestLimit($requestBytes);
        $post = (int) ini_get_number('post_max_size');
        if ($post > 0) { $budget = min($budget, $post); }
        // Includes JSON/RPC fields and even JSON encoders that escape every slash.
        return min(self::CHUNK_BYTES, max(0, intdiv(max(0, $budget - 512), 8) * 3));
    }

    public static function execute($name, array $args, $uploadLimit, $requestLimit, callable $readIssue) {
        if ($name === 'mantis_begin_attachment_upload') {
            call_user_func($readIssue, $args['issue_id']);
            return ProjectContext::run($args['issue_id'], function() use ($args, $uploadLimit, $requestLimit) {
                AttachmentUpload::authorize($args['issue_id'], $args, $uploadLimit, $args['size']);
                return self::begin($args, $requestLimit);
            });
        }
        $id = $args['upload_id'];
        $root = self::root(false);
        $base = $root . '/' . $id;
        if (!preg_match('/\A[a-f0-9]{64}\z/', $id) || is_link($base . '.lock') || !is_file($base . '.lock')) {
            throw new ToolFailure('Upload session is unavailable. Read the issue before starting another upload.');
        }
        $lock = @fopen($base . '.lock', 'r+b');
        if ($lock === false) { throw new ToolFailure('Upload session is unavailable.'); }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) { throw new ToolFailure('Upload session is busy; inspect its status before retrying.'); }
            $state = self::load($base);
            if ($state['owner_id'] !== (int) auth_get_current_user_id()) { throw new ToolFailure('Upload session is unavailable.'); }
            if ($state['expires_at'] <= time()) {
                self::discard($base);
                throw new ToolFailure('Upload session expired. Read the issue before starting another upload.');
            }
            call_user_func($readIssue, $state['issue_id']);
            return ProjectContext::run($state['issue_id'], function() use ($name, $args, $uploadLimit, $requestLimit, $base, &$state) {
                if ($name === 'mantis_get_attachment_upload') { return self::status($state, $base, $requestLimit); }
                if ($name === 'mantis_cancel_attachment_upload') {
                    if ($state['state'] !== 'complete') {
                        self::removeData($base);
                        $state['native_write_unconfirmed'] = in_array($state['state'], array('finalizing', 'unconfirmed'), true)
                            || !empty($state['native_write_unconfirmed']);
                        $state['state'] = 'cancelled';
                        unset($state['new_note']);
                        $state['expires_at'] = time() + self::IDLE_SECONDS;
                        self::save($base, $state);
                    }
                    return self::status($state, $base, $requestLimit);
                }
                if ($state['state'] === 'complete') {
                    if ($name === 'mantis_finish_attachment_upload') {
                        AttachmentDownload::authorize($state['issue_id'], $state['receipt']['attachment_id'], PHP_INT_MAX, false);
                        return (object) $state['receipt'];
                    }
                    throw new ToolFailure('Upload is already complete; use its existing receipt.');
                }
                if ($state['state'] !== 'uploading') {
                    throw new ToolFailure('Upload cannot continue. Read the issue before starting another upload; finalization may have completed.');
                }
                AttachmentUpload::authorize($state['issue_id'], $state, $uploadLimit, $state['size']);
                if ($name === 'mantis_upload_attachment_chunk') {
                    return self::append($base, $state, $args, $requestLimit);
                }
                return self::finish($base, $state, $uploadLimit);
            });
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private static function directory() {
        return sys_get_temp_dir() . '/.mantis-mcp-uploads-' . substr(hash('sha256', config_get_global('path')), 0, 20);
    }

    private static function root($create) {
        $root = self::directory();
        if (!file_exists($root) && $create && !@mkdir($root, 0700) && !is_dir($root)) {
            throw new ToolFailure('Could not create private upload staging.');
        }
        if (is_link($root) || !is_dir($root) || (fileperms($root) & 0777) !== 0700) {
            throw new ToolFailure('Private upload staging is unavailable.');
        }
        return $root;
    }

    private static function begin(array $args, $requestLimit) {
        $root = self::root(true);
        self::collectExpired();
        if (self::chunkLimit($requestLimit) < 1) { throw new ToolFailure('Request limit is too small for an upload chunk.'); }
        $id = bin2hex(random_bytes(32));
        $base = $root . '/' . $id;
        $lock = @fopen($base . '.lock', 'x+b');
        if ($lock === false) { throw new ToolFailure('Could not create an upload session.'); }
        $data = null;
        try {
            if (!chmod($base . '.lock', 0600) || !flock($lock, LOCK_EX)) { throw new ToolFailure('Could not secure an upload session.'); }
            $data = @fopen($base . '.data', 'x+b');
            if ($data === false || !chmod($base . '.data', 0600)) { throw new ToolFailure('Could not create private upload data.'); }
            $state = $args;
            $state['upload_id'] = $id;
            $state['owner_id'] = (int) auth_get_current_user_id();
            $state['received'] = 0;
            $state['state'] = 'uploading';
            $state['expires_at'] = time() + self::IDLE_SECONDS;
            self::save($base, $state);
            return self::status($state, $base, $requestLimit);
        } catch (\Throwable $e) {
            foreach (array('.data', '.json', '.lock') as $suffix) { if (is_file($base . $suffix)) { @unlink($base . $suffix); } }
            throw $e;
        } finally {
            if (is_resource($data)) { fclose($data); }
            flock($lock, LOCK_UN); fclose($lock);
        }
    }

    private static function load($base) {
        if (is_link($base . '.json') || !is_file($base . '.json')) { throw new ToolFailure('Upload session is unavailable.'); }
        $stream = @fopen($base . '.json', 'rb');
        if ($stream === false) { throw new ToolFailure('Upload session is unavailable.'); }
        try { $raw = stream_get_contents($stream, self::MAX_STATE_BYTES + 1); }
        finally { fclose($stream); }
        $state = is_string($raw) && strlen($raw) <= self::MAX_STATE_BYTES ? json_decode($raw, true) : null;
        if (!is_array($state) || !isset($state['owner_id'], $state['issue_id'], $state['size'], $state['state'],
            $state['received'], $state['sha256'], $state['expires_at'])) { throw new ToolFailure('Upload session state is unavailable.'); }
        if (!is_int($state['owner_id']) || !is_int($state['issue_id']) || !is_int($state['size'])
            || $state['size'] < 1 || !is_int($state['received']) || $state['received'] < 0
            || $state['received'] > $state['size'] || !is_int($state['expires_at'])
            || !in_array($state['state'], array('uploading', 'finalizing', 'complete', 'unconfirmed', 'cancelled'), true)
            || !isset($state['upload_id'], $state['filename'], $state['mime_type'])
            || $state['upload_id'] !== basename($base) || !is_string($state['sha256'])
            || !preg_match('/\A[a-f0-9]{64}\z/', $state['sha256'])) {
            throw new ToolFailure('Upload session state is unavailable.');
        }
        return $state;
    }

    private static function save($base, array $state) {
        $json = json_encode($state);
        if ($json === false || strlen($json) > self::MAX_STATE_BYTES) { throw new ToolFailure('Could not encode upload session state.'); }
        $temp = tempnam(dirname($base), '.state-');
        if ($temp === false) { throw new ToolFailure('Could not persist upload session state.'); }
        try {
            if (!chmod($temp, 0600) || file_put_contents($temp, $json) !== strlen($json)
                || !rename($temp, $base . '.json')) { throw new ToolFailure('Could not persist upload session state.'); }
            @touch($base . '.lock');
        } finally { if (is_file($temp)) { @unlink($temp); } }
    }

    private static function received($base, array $state) {
        if (is_link($base . '.data') || !is_file($base . '.data')) { throw new ToolFailure('Upload data is unavailable.'); }
        clearstatcache(true, $base . '.data');
        $actual = filesize($base . '.data');
        if ($actual === false || $actual < $state['received'] || $actual > $state['size']) {
            throw new ToolFailure('Upload data size is inconsistent; cancel this session.');
        }
        return $actual;
    }

    private static function status(array $state, $base, $requestLimit) {
        $received = $state['state'] === 'uploading' ? self::received($base, $state) : $state['received'];
        $result = (object) array('upload_id' => $state['upload_id'], 'issue_id' => $state['issue_id'],
            'filename' => $state['filename'], 'mime_type' => $state['mime_type'], 'size' => $state['size'],
            'sha256' => $state['sha256'], 'received_bytes' => $received, 'next_offset' => $received,
            'state' => $state['state'], 'expires_at' => $state['expires_at'],
            'native_write_unconfirmed' => in_array($state['state'], array('finalizing', 'unconfirmed'), true)
                || !empty($state['native_write_unconfirmed']),
            'max_chunk_bytes' => self::chunkLimit($requestLimit));
        if (isset($state['receipt'])) {
            AttachmentDownload::authorize($state['issue_id'], $state['receipt']['attachment_id'], PHP_INT_MAX, false);
            $result->receipt = (object) $state['receipt'];
        }
        return $result;
    }

    private static function append($base, array &$state, array $args, $requestLimit) {
        $encoded = $args['content_base64'];
        $max = self::chunkLimit($requestLimit);
        if (strlen($encoded) > 4 * (int) ceil($max / 3)) { throw new ToolFailure('Chunk exceeds the per-request chunk limit.'); }
        $bytes = base64_decode($encoded, true);
        if ($bytes === false || $bytes === '' || base64_encode($bytes) !== $encoded || strlen($bytes) > $max
            || !hash_equals($args['sha256'], hash('sha256', $bytes))) { throw new ToolFailure('Chunk encoding, size or SHA-256 is invalid.'); }
        $state['received'] = self::received($base, $state);
        $offset = $args['offset']; $length = strlen($bytes);
        if ($offset > $state['received'] || $length > $state['size'] - $offset) { throw new ToolFailure('Unexpected chunk offset or declared file size exceeded; inspect upload status.'); }
        $file = @fopen($base . '.data', 'r+b');
        if ($file === false) { throw new ToolFailure('Could not open private upload data.'); }
        try {
            if (fseek($file, $offset) !== 0) { throw new ToolFailure('Could not position upload data.'); }
            if ($offset < $state['received']) {
                if ($length > $state['received'] - $offset || fread($file, $length) !== $bytes) {
                    throw new ToolFailure('Retried chunk differs from stored bytes or overlaps new data; inspect upload status.');
                }
            } else {
                $written = 0;
                while ($written < $length) {
                    $count = fwrite($file, substr($bytes, $written));
                    if ($count === false || $count === 0) { throw new ToolFailure('Chunk write was incomplete; inspect upload status before retrying.'); }
                    $written += $count;
                }
                if (!fflush($file)) { throw new ToolFailure('Chunk flush was unconfirmed; inspect upload status.'); }
                $state['received'] += $length;
            }
        } finally { fclose($file); }
        $state['expires_at'] = time() + self::IDLE_SECONDS;
        self::save($base, $state);
        return self::status($state, $base, $requestLimit);
    }

    private static function finish($base, array &$state, $uploadLimit) {
        $state['received'] = self::received($base, $state);
        if ($state['received'] !== $state['size']) { throw new ToolFailure('Upload is incomplete; inspect upload status and send the remaining bytes.'); }
        if (!hash_equals($state['sha256'], hash_file('sha256', $base . '.data'))) { throw new ToolFailure('Assembled attachment SHA-256 does not match the original; cancel this session.'); }
        $state['state'] = 'finalizing';
        self::save($base, $state); // Persisted no-repeat barrier before any native write.
        try {
            $newNote = null;
            if (isset($state['new_note'])) {
                $n = $state['new_note'];
                $newNote = array('text' => $n['text']);
                if (array_key_exists('private', $n)) { $newNote['view_state'] = array('name' => $n['private'] ? 'private' : 'public'); }
            }
            $receipt = AttachmentUpload::fromStaged($state['issue_id'], $state, $uploadLimit,
                $base . '.data', $state['size'], $state['sha256'], $newNote);
            $state['state'] = 'complete';
            $state['receipt'] = (array) $receipt;
            unset($state['new_note']);
            $state['expires_at'] = time() + self::IDLE_SECONDS;
            self::save($base, $state);
            try { self::removeData($base); }
            catch (\Throwable $cleanup) { error_log('MantisMcp: completed upload staging cleanup failed.'); }
            return $receipt;
        } catch (\Throwable $error) {
            $state['state'] = 'unconfirmed';
            try { self::save($base, $state); } catch (\Throwable $ignored) { /* finalizing barrier remains */ }
            throw new ToolFailure('Upload finalization is unconfirmed. Read the issue before starting another upload; this session will not repeat the native write.');
        }
    }

    private static function removeData($base) {
        if (is_link($base . '.data')) { throw new ToolFailure('Upload data is unavailable.'); }
        if (is_file($base . '.data') && !unlink($base . '.data')) { throw new ToolFailure('Could not clean up staged upload data.'); }
    }

    /** Internal maintenance entry point; no MCP tool accepts cleanup paths. */
    public static function collectExpired() {
        $result = array('expired_sessions' => 0, 'orphan_files' => 0, 'busy_sessions' => 0, 'failed' => 0);
        if (!file_exists(self::directory()) && !is_link(self::directory())) { return $result; }
        $root = self::root(false);
        $path = $root . '/.gc.lock';
        if (is_link($path)) { throw new ToolFailure('Upload maintenance is unavailable.'); }
        $gate = @fopen($path, 'c+b');
        if ($gate === false) { throw new ToolFailure('Upload maintenance is unavailable.'); }
        try {
            if (!chmod($path, 0600)) { throw new ToolFailure('Could not secure upload maintenance.'); }
            if (!flock($gate, LOCK_EX | LOCK_NB)) { return $result; }
            return self::collect($root, $result);
        } finally { flock($gate, LOCK_UN); fclose($gate); }
    }

    private static function discard($base) {
        self::removeData($base); // Keep metadata/lock for a retry if data cleanup fails.
        foreach (array('.json', '.lock') as $suffix) {
            $path = $base . $suffix;
            if (is_link($path) || (is_file($path) && !unlink($path))) {
                throw new ToolFailure('Could not remove expired upload metadata.');
            }
        }
    }

    private static function collect($root, array $result) {
        $scanned = 0;
        foreach (new \DirectoryIterator($root) as $file) {
            if ($file->isLink() || !$file->isFile() || $file->getMTime() + self::IDLE_SECONDS >= time()) { continue; }
            $name = $file->getFilename();
            if (strpos($name, '.state-') === 0
                || (preg_match('/\A([a-f0-9]{64})\.(data|json)\z/', $name, $orphan)
                    && !file_exists($root . '/' . $orphan[1] . '.lock') && !is_link($root . '/' . $orphan[1] . '.lock'))) {
                if (++$scanned > 100) { break; }
                if (@unlink($file->getPathname())) { ++$result['orphan_files']; } else { ++$result['failed']; }
                continue;
            }
            if (!preg_match('/\A([a-f0-9]{64})\.lock\z/', $name, $match)) { continue; }
            if (++$scanned > 100) { break; }
            $base = $root . '/' . $match[1];
            $lock = @fopen($base . '.lock', 'r+b');
            if ($lock === false) { continue; }
            try {
                if (!flock($lock, LOCK_EX | LOCK_NB)) { ++$result['busy_sessions']; continue; }
                try { $state = self::load($base); if ($state['expires_at'] > time()) { continue; } }
                catch (\Throwable $ignored) { /* old incomplete metadata is disposable */ }
                try { self::discard($base); ++$result['expired_sessions']; }
                catch (\Throwable $ignored) { ++$result['failed']; }
            } finally { flock($lock, LOCK_UN); fclose($lock); }
        }
        return $result;
    }
}
