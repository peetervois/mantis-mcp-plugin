<?php
/** SPDX-License-Identifier: BSD-3-Clause */
use Tauria\MantisMcp\UploadSessions;

// Never share test staging with a real installation or another test process.
$GLOBALS['test_mantis_session_url'] = 'https://session-fixture.invalid/' . bin2hex(random_bytes(12)) . '/';
load_native_function(getenv('MANTISBT_SOURCE') . '/core/config_api.php', 'config_get_global', 'native_upload_config_default');
function config_eval($value, $global = false) { return $value; }
function session_root() {
    return sys_get_temp_dir() . '/.mantis-mcp-uploads-' . substr(hash('sha256', config_get_global('path')), 0, 20);
}
function reset_upload_staging() {
    $root = session_root();
    if (is_link($root)) { unlink($root); return; }
    if (!is_dir($root)) { return; }
    foreach (new DirectoryIterator($root) as $entry) {
        if ($entry->isDot()) { continue; }
        if ($entry->isLink() || $entry->isFile()) { unlink($entry->getPathname()); }
    }
    rmdir($root);
}
function session_begin($bytes, $extra = array(), $token = 'alice-key') {
    $args = $extra + array('issue_id' => 94, 'filename' => 'chunked.bin', 'mime_type' => 'application/octet-stream',
        'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes));
    return management_call('mantis_begin_attachment_upload', $args, $token);
}
function session_chunk($id, $offset, $bytes, $extra = array(), $token = 'alice-key') {
    return management_call('mantis_upload_attachment_chunk', $extra + array('upload_id' => $id,
        'offset' => $offset, 'content_base64' => base64_encode($bytes), 'sha256' => hash('sha256', $bytes)), $token);
}
function session_call($name, $id, $token = 'alice-key') {
    return management_call('mantis_' . $name . '_attachment_upload', array('upload_id' => $id), $token);
}
function session_send_all($id, $bytes, $length = 262144) {
    for ($offset = 0; $offset < strlen($bytes); $offset += $length) {
        $r = session_chunk($id, $offset, substr($bytes, $offset, $length))->result;
        same(false, $r->isError, 'Chunk append failed at offset ' . $offset . ': ' . ($r->isError ? $r->content[0]->text : ''));
        same(min(strlen($bytes), $offset + $length), $r->structuredContent->next_offset);
    }
}

test_case('default upload cap inherits the native project setting with no fixed MCP total ceiling', function() {
    $GLOBALS['test_mantis']['max_upload_bytes'] = null;
    $GLOBALS['test_mantis']['max_file_size'] = 67108864;
    $GLOBALS['test_mantis']['php_limits'] = array('upload_max_filesize' => 134217728, 'post_max_size' => 134217728);
    $p = policy_call()->result->structuredContent;
    same(67108864, $p->limits->plugin_upload_bytes);
    same(67108864, $p->limits->decoded_upload_upper_bound_bytes);
    same('mantis', $p->limits->upload_limit_source);
    same(null, $p->limits->configured_upload_bytes);
    same(262144, $p->limits->upload_chunk_bytes);
    $GLOBALS['test_mantis']['max_upload_bytes'] = 1024;
    same(1024, policy_call()->result->structuredContent->limits->plugin_upload_bytes);
    $GLOBALS['test_mantis']['max_upload_bytes'] = 100663296;
    same(67108864, policy_call()->result->structuredContent->limits->decoded_upload_upper_bound_bytes);
});

test_case('automatic upload defaults use a native Mantis-compatible sentinel without missing-config warnings', function() {
    $default = (new MantisMcpPlugin('MantisMcp'))->config()['max_upload_bytes'];
    same(false, $default);
    unset($GLOBALS['g_upload_fixture_unset']);
    same(false, native_upload_config_default('upload_fixture_unset', $default));
    $GLOBALS['g_upload_fixture_unset'] = null;
    same(false, native_upload_config_default('upload_fixture_unset', $default));
    unset($GLOBALS['g_upload_fixture_unset']);
    $GLOBALS['test_mantis']['php_limits'] = array('upload_max_filesize' => 134217728, 'post_max_size' => 134217728);
    same(67108864, \Tauria\MantisMcp\AttachmentUpload::effectiveLimit($default, 67108864));
});

test_case('inherited policy does not expose a private native size and invalid overrides fail closed', function() {
    $GLOBALS['test_mantis']['max_upload_bytes'] = null;
    $GLOBALS['test_mantis']['private_configs'] = array('max_file_size');
    $p = policy_call()->result->structuredContent;
    same(null, $p->limits->plugin_upload_bytes);
    same(null, $p->limits->decoded_upload_upper_bound_bytes);
    foreach (array(0, -1, 'garbage', array()) as $bad) {
        $GLOBALS['test_mantis']['max_upload_bytes'] = $bad;
        same(true, policy_call()->result->isError);
    }
});

test_case('chunk sessions stage private bytes, safely replay chunks and finalize exactly once', function() {
    $GLOBALS['test_mantis']['writes'] = true;
    $bytes = str_repeat('original binary' . chr(0), 200);
    $begin = session_begin($bytes)->result;
    same(false, $begin->isError);
    $id = $begin->structuredContent->upload_id;
    same(0, $begin->structuredContent->received_bytes);
    same(0700, fileperms(session_root()) & 0777);
    foreach (array('.lock', '.json', '.data') as $suffix) { same(0600, fileperms(session_root() . '/' . $id . $suffix) & 0777); }
    same(300, $GLOBALS['test_mantis']['last_file_id']);
    $a = session_chunk($id, 0, substr($bytes, 0, 512))->result;
    $replay = session_chunk($id, 0, substr($bytes, 0, 512))->result;
    same(false, $replay->isError); same(512, $replay->structuredContent->received_bytes);
    same(true, session_chunk($id, 0, str_repeat('x', 512))->result->isError);
    same(true, session_chunk($id, 513, 'gap')->result->isError);
    same(true, session_call('finish', $id)->result->isError);
    same(false, session_chunk($id, 512, substr($bytes, 512))->result->isError);
    $finished = session_call('finish', $id)->result;
    same(false, $finished->isError);
    same(hash('sha256', $bytes), $finished->structuredContent->sha256);
    same($bytes, $GLOBALS['test_mantis']['attachments'][$finished->structuredContent->attachment_id]['content']);
    same((array) $finished->structuredContent, (array) session_call('finish', $id)->result->structuredContent);
    same(301, $GLOBALS['test_mantis']['last_file_id']);
    same('complete', session_call('get', $id)->result->structuredContent->state);
    check(!is_file(session_root() . '/' . $id . '.data'));
    $cancel = session_call('cancel', $id)->result;
    same('complete', $cancel->structuredContent->state);
    check(isset($GLOBALS['test_mantis']['attachments'][301]));
});

test_case('assembled files exceed old MCP and per-request HTTP limits while native validation stays authoritative', function() {
    $GLOBALS['test_mantis']['writes'] = true;
    $GLOBALS['test_mantis']['max_upload_bytes'] = null;
    $GLOBALS['test_mantis']['max_file_size'] = 8 * 1024 * 1024;
    $GLOBALS['test_mantis']['max_download_bytes'] = 8 * 1024 * 1024;
    $GLOBALS['test_mantis']['php_limits'] = array('upload_max_filesize' => 8388608, 'post_max_size' => 8388608);
    $bytes = str_repeat(implode('', array_map('chr', range(0, 255))), 24577);
    check(strlen($bytes) > 5 * 1024 * 1024);
    $begin = session_begin($bytes)->result;
    same(false, $begin->isError, 'Large-file begin failed');
    $id = $begin->structuredContent->upload_id;
    session_send_all($id, $bytes);
    $done = session_call('finish', $id)->result;
    same(false, $done->isError, 'Large-file finalize failed: ' . ($done->isError ? $done->content[0]->text : ''));
    same(strlen($bytes), $done->structuredContent->size);
    $read = ''; $offset = 0;
    do {
        $d = decoded(download_call(array('attachment_id' => $done->structuredContent->attachment_id,
            'offset' => $offset, 'expected_sha256' => hash('sha256', $bytes))))->result;
        same(false, $d->isError, 'Large-file download failed: ' . ($d->isError ? $d->content[0]->text : ''));
        $read .= base64_decode($d->structuredContent->content_base64);
        $offset = $d->structuredContent->next_offset;
    } while (!$d->structuredContent->complete);
    same($bytes, $read);
    same(hash('sha256', $bytes), $done->structuredContent->sha256);
});

test_case('chunk sessions support native new private notes and existing-note attachment metadata', function() {
    $GLOBALS['test_mantis']['writes'] = true;
    foreach (array(array('issue_id' => 96, 'new_note' => (object) array('text' => 'Private explanation', 'private' => true)),
        array('note_id' => 1302)) as $extra) {
        $bytes = 'Original note file ' . chr(0);
        $id = session_begin($bytes, $extra)->result->structuredContent->upload_id;
        check(strpos(json_encode(session_call('get', $id)), 'Private explanation') === false);
        session_send_all($id, $bytes);
        $r = session_call('finish', $id)->result;
        same(false, $r->isError);
        $note = $GLOBALS['test_mantis']['notes'][$r->structuredContent->note_id];
        same(50, $note['view_state']); same(1, $note['reporter_id']);
        same(isset($extra['note_id']) ? 'Original note' : 'Private explanation', $note['text']);
    }
});

test_case('session locking rejects concurrent writes and recovers progress after a metadata interruption', function() {
    $GLOBALS['test_mantis']['writes'] = true;
    $id = session_begin('abcdef')->result->structuredContent->upload_id;
    $base = session_root() . '/' . $id;
    $lock = fopen($base . '.lock', 'r+b');
    try {
        check(flock($lock, LOCK_EX | LOCK_NB));
        same(true, session_chunk($id, 0, 'abc')->result->isError);
        same(0, filesize($base . '.data'));
    } finally { flock($lock, LOCK_UN); fclose($lock); }
    // Simulate a completed data write whose progress metadata was not saved.
    file_put_contents($base . '.data', 'abc');
    same(3, session_call('get', $id)->result->structuredContent->next_offset);
    same(false, session_chunk($id, 0, 'abc')->result->isError);
    same(false, session_chunk($id, 3, 'def')->result->isError);
    $done = session_call('finish', $id)->result;
    same(false, $done->isError);
    same(hash('sha256', 'abcdef'), $done->structuredContent->sha256);
    // A deleted native file must not be advertised again by an old receipt.
    unset($GLOBALS['test_mantis']['attachments'][$done->structuredContent->attachment_id]);
    same(true, session_call('finish', $id)->result->isError);
    same(true, session_call('get', $id)->result->isError);
    same(301, $GLOBALS['test_mantis']['last_file_id']);
});

test_case('chunk ownership, revoked issue access and changed native limits are enforced before writes', function() {
    $GLOBALS['test_mantis']['writes'] = true;
    $bytes = str_repeat('a', 2000);
    $id = session_begin($bytes)->result->structuredContent->upload_id;
    $GLOBALS['test_mantis']['calls'] = array();
    same(true, session_call('get', $id, 'bob-key')->result->isError);
    same(array(), $GLOBALS['test_mantis']['calls']);
    same(true, session_chunk($id, 0, 'a', array(), 'bob-key')->result->isError);
    $GLOBALS['test_mantis']['denied_issue_ids'] = array(94);
    same(true, session_chunk($id, 0, 'a')->result->isError);
    $GLOBALS['test_mantis']['denied_issue_ids'] = array();
    same(0, session_call('get', $id)->result->structuredContent->received_bytes);
    $GLOBALS['test_mantis']['max_file_size'] = 1000;
    same(true, session_chunk($id, 0, 'a')->result->isError);
    same(true, session_call('finish', $id)->result->isError);
    same('cancelled', session_call('cancel', $id)->result->structuredContent->state);
    same(300, $GLOBALS['test_mantis']['last_file_id']);
});

test_case('chunk hash, canonical encoding, offsets and total integrity are verified without Mantis records', function() {
    $GLOBALS['test_mantis']['writes'] = true;
    $id = session_begin('abcd')->result->structuredContent->upload_id;
    same(true, session_chunk($id, 0, 'ab', array('sha256' => str_repeat('0', 64)))->result->isError);
    same(true, session_chunk($id, 0, 'ab', array('content_base64' => 'Zh=='))->result->isError);
    same(true, session_chunk($id, 0, 'abcde')->result->isError);
    session_send_all($id, 'abce');
    same(true, session_call('finish', $id)->result->isError);
    same(300, $GLOBALS['test_mantis']['last_file_id']);
    same('cancelled', session_call('cancel', $id)->result->structuredContent->state);
    same('cancelled', session_call('cancel', $id)->result->structuredContent->state);
});

test_case('uncertain native finalization is never retried or duplicated by the session', function() {
    $GLOBALS['test_mantis']['writes'] = true;
    $id = session_begin('uncertain stored bytes')->result->structuredContent->upload_id;
    session_send_all($id, 'uncertain stored bytes');
    $GLOBALS['test_mantis']['storage_failure'] = 'after_insert';
    same(true, session_call('finish', $id)->result->isError);
    same(301, $GLOBALS['test_mantis']['last_file_id']);
    same('unconfirmed', session_call('get', $id)->result->structuredContent->state);
    $GLOBALS['test_mantis']['storage_failure'] = null;
    same(true, session_call('finish', $id)->result->isError);
    same(301, $GLOBALS['test_mantis']['last_file_id']);
    same(true, session_call('cancel', $id)->result->structuredContent->native_write_unconfirmed);
    check(isset($GLOBALS['test_mantis']['attachments'][301]));
});

test_case('chunk finalization uses native disk storage without deleting the completed file on cleanup', function() {
    $GLOBALS['test_mantis']['writes'] = true;
    $dir = sys_get_temp_dir() . '/native-disk-fixture-' . bin2hex(random_bytes(8));
    mkdir($dir, 0700);
    $GLOBALS['test_mantis']['storage_dir'] = $dir;
    $GLOBALS['test_mantis']['file_method'] = DISK;
    $bytes = 'Original file on disk';
    $id = session_begin($bytes)->result->structuredContent->upload_id;
    session_send_all($id, $bytes);
    $done = session_call('finish', $id)->result;
    same(false, $done->isError);
    $row = $GLOBALS['test_mantis']['attachments'][$done->structuredContent->attachment_id];
    same($bytes, file_get_contents($dir . '/' . $row['diskfile']));
    check(!is_file(session_root() . '/' . $id . '.data'));
    same(false, session_call('finish', $id)->result->isError);
});

test_case('expired staging is denied and collected while symlink data cannot be read or written', function() {
    $GLOBALS['test_mantis']['writes'] = true;
    $id = session_begin('expired')->result->structuredContent->upload_id;
    $base = session_root() . '/' . $id;
    $state = json_decode(file_get_contents($base . '.json'), true);
    $state['expires_at'] = time() - 1;
    file_put_contents($base . '.json', json_encode($state));
    touch($base . '.lock', time() - 7200);
    same(true, session_call('get', $id)->result->isError);
    $next = session_begin('new bytes')->result->structuredContent->upload_id;
    check(!is_file($base . '.data')); check(!is_file($base . '.json'));
    $path = session_root() . '/' . $next . '.data';
    $outside = tempnam(sys_get_temp_dir(), 'outside-fixture-'); file_put_contents($outside, 'protected bytes');
    unlink($path); symlink($outside, $path);
    try {
        same(true, session_chunk($next, 0, 'new bytes')->result->isError);
        same('protected bytes', file_get_contents($outside));
    } finally { unlink($path); unlink($outside); }
});

test_case('session schemas reject arbitrary paths, impersonation, note conflicts and disabled writes', function() {
    same(-32602, session_begin('bytes')->error->code);
    $GLOBALS['test_mantis']['writes'] = true;
    foreach (array(array('path' => '/tmp/other'), array('owner_id' => 2), array('size' => 0),
        array('note_id' => 1302, 'new_note' => (object) array('text' => 'conflict'))) as $extra) {
        same(-32602, session_begin('bytes', $extra)->error->code);
    }
    same(-32602, session_call('get', '../other')->error->code);
    same(300, $GLOBALS['test_mantis']['last_file_id']);
});
