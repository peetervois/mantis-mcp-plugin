<?php
/**
 * SPDX-License-Identifier: BSD-3-Clause
 * Copyright (c) 2026, Peeter Vois
 */

function download_call($overrides = array(), $token = 'alice-key', $options = array()) {
    return send_request(tool_call('mantis_download_attachment', (object) ($overrides
        + array('issue_id' => 94, 'attachment_id' => 219))), $token, $options);
}
function file_reads() {
    return count(array_filter($GLOBALS['test_mantis']['calls'],
        function($call) { return $call['name'] === 'files'; }));
}
function set_attachment_bytes($bytes, $id = 219) {
    $GLOBALS['test_mantis']['attachments'][$id]['bytes'] = $bytes;
    $GLOBALS['test_mantis']['attachments'][$id]['filesize'] = strlen($bytes);
}

test_case('discovers attachment download as a read-only tool with an output schema', function() {
    $tools = decoded(send_request(rpc('tools/list')))->result->tools;
    $download = end($tools);
    same('mantis_download_attachment', $download->name);
    same(true, $download->annotations->readOnlyHint);
    same(false, $download->annotations->destructiveHint);
    same(true, $download->annotations->idempotentHint);
    check(isset($download->outputSchema->properties->content_base64));
});

test_case('reconstructs a binary file from bounded chunks without base64 in text output', function() {
    $original = $GLOBALS['test_mantis']['attachments'][219]['bytes'];
    $output = '';
    $offset = 0;
    $hash = null;
    do {
        $arguments = array('offset' => $offset);
        if ($hash !== null) {
            $arguments['expected_sha256'] = $hash;
        }
        $response = download_call($arguments);
        same(200, $response->getStatusCode());
        $result = decoded($response)->result;
        same(false, $result->isError);
        $chunk = $result->structuredContent;
        same('LOGO_TAURIA_OÜ.jpg', $chunk->filename);
        same('image/jpeg', $chunk->mime_type);
        same(1302, $chunk->note_id);
        same(strlen($original), $chunk->size);
        same($offset, $chunk->offset);
        $decoded = base64_decode($chunk->content_base64, true);
        same($chunk->bytes_returned, strlen($decoded));
        check($chunk->bytes_returned <= 65536);
        check(strpos($result->content[0]->text, 'content_base64') === false);
        $output .= $decoded;
        $hash = $chunk->sha256;
        $offset = $chunk->next_offset;
    } while (!$chunk->complete);
    same($original, $output);
    same(hash('sha256', $original), $hash);
    same(null, $offset);
    same(6, file_reads());
    $call = end($GLOBALS['test_mantis']['calls']);
    same(array('id' => 94, 'file_id' => 219), $call['path']);
    same('GET', $call['method']);
    same(array(), $call['query']);
});

test_case('downloads the exact PNG fixture with write tools disabled', function() {
    $bytes = file_get_contents(dirname(__DIR__) . '/chatgpt/tauria-tasks/assets/IconLDC-bt3.png');
    set_attachment_bytes($bytes);
    $GLOBALS['test_mantis']['attachments'][219]['file_type'] = 'image/png';
    $chunk = decoded(download_call())->result->structuredContent;
    same(true, $chunk->complete);
    same($bytes, base64_decode($chunk->content_base64, true));
    same(false, $GLOBALS['test_mantis']['writes']);
});

test_case('checks issue access and attachment ownership before reading any file content', function() {
    same(true, decoded(download_call(array(), 'bob-key'))->result->isError);
    same(true, decoded(download_call(array('attachment_id' => 222)))->result->isError);
    same(true, decoded(download_call(array('attachment_id' => 999)))->result->isError);
    same(0, file_reads());
});

test_case('enforces private-note access even when both accounts can read the issue', function() {
    $arguments = array('issue_id' => 96, 'attachment_id' => 220);
    same(false, decoded(download_call($arguments))->result->isError);
    same(true, decoded(download_call($arguments, 'bob-key'))->result->isError);
    same(false, decoded(download_call($arguments))->result->isError);
    same(2, file_reads());
    same(false, decoded(download_call(array('issue_id' => 96, 'attachment_id' => 221), 'bob-key'))
        ->result->isError);
});

test_case('enforces native download thresholds separately from attachment visibility', function() {
    $GLOBALS['test_mantis']['download_threshold'] = 60;
    same(true, decoded(download_call())->result->isError);
    same(0, file_reads());
    $GLOBALS['test_mantis']['allow_download_own'] = true;
    same(false, decoded(download_call())->result->isError);
});

test_case('does not let own-file permission bypass an unreadable note', function() {
    $GLOBALS['test_mantis']['download_threshold'] = 60;
    $GLOBALS['test_mantis']['allow_download_own'] = true;
    $GLOBALS['test_mantis']['attachments'][220]['note_readers'] = array(2);
    same(true, decoded(download_call(array('issue_id' => 96, 'attachment_id' => 220)))->result->isError);
    same(0, file_reads());
});

test_case('rechecks token validity for each resumed download request', function() {
    $first = decoded(download_call())->result->structuredContent;
    unset($GLOBALS['test_mantis']['tokens']['alice-key']);
    $response = download_call(array('offset' => $first->next_offset, 'expected_sha256' => $first->sha256));
    same(401, $response->getStatusCode());
    same(1, file_reads());
});

test_case('rejects invalid ranges, IDs, hashes and untrusted source arguments before dispatch', function() {
    foreach (array(array('issue_id' => 0), array('attachment_id' => '219'),
        array('offset' => -1), array('offset' => 1), array('length' => 0), array('length' => 65537),
        array('expected_sha256' => 'invalid'), array('url' => 'https://example.com/file'),
        array('path' => '/etc/passwd')) as $arguments) {
        same(-32602, decoded(download_call($arguments))->error->code);
    }
    same(array(), $GLOBALS['test_mantis']['calls']);
});

test_case('rejects oversized attachments and out-of-bounds offsets before reading bytes', function() {
    $GLOBALS['test_mantis']['max_download_bytes'] = 1024;
    same(true, decoded(download_call())->result->isError);
    same(0, file_reads());
    $GLOBALS['test_mantis']['max_download_bytes'] = 5242880;
    same(true, decoded(download_call(array('offset' => 999999,
        'expected_sha256' => str_repeat('a', 64))))->result->isError);
    same(0, file_reads());
});

test_case('pins resumed downloads to one content hash and rejects changed bytes', function() {
    $first = decoded(download_call())->result->structuredContent;
    $bytes = $GLOBALS['test_mantis']['attachments'][219]['bytes'];
    set_attachment_bytes(str_repeat('x', strlen($bytes)));
    $result = decoded(download_call(array('offset' => $first->next_offset,
        'expected_sha256' => $first->sha256)))->result;
    same(true, $result->isError);
    check(strpos($result->content[0]->text, 'Discard partial') !== false);
    check(!isset($result->structuredContent));
});

test_case('does not return partial success for invalid native file responses', function() {
    $file = array('id' => 219, 'filename' => 'LOGO_TAURIA_OÜ.jpg',
        'size' => $GLOBALS['test_mantis']['attachments'][219]['filesize'],
        'content' => base64_encode($GLOBALS['test_mantis']['attachments'][219]['bytes']));
    foreach (array(array('files' => array()), array('files' => array($file, $file)),
        array('files' => array(array('id' => 219))),
        array('files' => array(array_merge($file, array('id' => 222)))),
        array('files' => array(array_merge($file, array('content' => 'not base64!')))),
        array('files' => array(array_merge($file, array('content' => base64_encode('truncated')))))) as $response) {
        $GLOBALS['test_mantis']['file_response_override'] = $response;
        $result = decoded(download_call())->result;
        same(true, $result->isError);
        check(!isset($result->structuredContent));
    }
});

test_case('rejects encoded content exceeding the cap despite small stored metadata', function() {
    $GLOBALS['test_mantis']['max_download_bytes'] = 1024;
    set_attachment_bytes('small');
    $GLOBALS['test_mantis']['file_response_override'] = array('files' => array(array(
        'id' => 219, 'filename' => 'LOGO_TAURIA_OÜ.jpg', 'size' => 5,
        'content' => base64_encode(str_repeat('x', 2048)),
    )));
    $result = decoded(download_call())->result;
    same(true, $result->isError);
    check(!isset($result->structuredContent));
});

test_case('downloads beyond the former fixed ceilings with bounded responses and exact whole-file integrity', function() {
    $bytes = str_repeat(implode('', array_map('chr', range(0, 255))), 32769);
    check(strlen($bytes) > 8 * 1024 * 1024);
    set_attachment_bytes($bytes);
    $GLOBALS['test_mantis']['max_file_size'] = 1024; // Existing downloads do not inherit today's upload limit.
    $offset = 0; $hash = hash('sha256', $bytes); $digest = hash_init('sha256');
    do {
        $r = decoded(download_call(array('offset' => $offset, 'expected_sha256' => $hash)))->result;
        same(false, $r->isError);
        $c = $r->structuredContent; $part = base64_decode($c->content_base64, true);
        same(substr($bytes, $offset, 65536), $part);
        check(strlen($part) <= 65536); same($hash, $c->sha256);
        hash_update($digest, $part); $offset += strlen($part);
    } while (!$c->complete);
    same(strlen($bytes), $offset); same($hash, hash_final($digest));
    $GLOBALS['test_mantis']['max_download_bytes'] = 16 * 1024 * 1024;
    same(false, decoded(download_call())->result->isError);
    $reads = file_reads();
    $GLOBALS['test_mantis']['max_download_bytes'] = 1024;
    same(true, decoded(download_call())->result->isError);
    same($reads, file_reads()); // An explicit cap still rejects before reading native content.
});

test_case('handles empty files and EOF without inventing extra content', function() {
    set_attachment_bytes('');
    $chunk = decoded(download_call())->result->structuredContent;
    same('', $chunk->content_base64);
    same(0, $chunk->bytes_returned);
    same(true, $chunk->complete);
    same(null, $chunk->next_offset);
    same(hash('sha256', ''), $chunk->sha256);
    set_attachment_bytes('abc');
    $chunk = decoded(download_call(array('offset' => 3, 'expected_sha256' => hash('sha256', 'abc'))))
        ->result->structuredContent;
    same('', $chunk->content_base64);
    same(true, $chunk->complete);
});

test_case('rejects unsafe filenames and normalizes MIME metadata without executing content', function() {
    foreach (array('../secret.jpg', 'C:\\secret.jpg', "image\n.jpg", '.', '..') as $filename) {
        $GLOBALS['test_mantis']['attachments'][219]['filename'] = $filename;
        same(true, decoded(download_call())->result->isError);
    }
    $GLOBALS['test_mantis']['attachments'][219]['filename'] = 'Näide-测试.png';
    $GLOBALS['test_mantis']['attachments'][219]['file_type'] = "bad\nmime";
    $chunk = decoded(download_call())->result->structuredContent;
    same('Näide-测试.png', $chunk->filename);
    same('application/octet-stream', $chunk->mime_type);
});
