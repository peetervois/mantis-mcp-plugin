<?php
/** SPDX-License-Identifier: BSD-3-Clause */

function upload_arguments($overrides = array()) {
    $bytes = file_get_contents(dirname(__DIR__) . '/chatgpt/tauria-tasks/assets/IconLDC-bt3.png');
    return (object) ($overrides + array('issue_id' => 94, 'filename' => 'IconLDC-bt3.png',
        'mime_type' => 'image/png', 'content_base64' => base64_encode($bytes),
        'sha256' => hash('sha256', $bytes)));
}
function upload_call($overrides = array(), $token = 'alice-key', $options = array()) {
    return send_request(tool_call('mantis_upload_attachment', upload_arguments($overrides)), $token, $options);
}
function staging_files() { $files = glob(sys_get_temp_dir() . '/mantismcp-*'); sort($files); return $files; }

test_case('hides uploads without write opt-in and rejects disabled uploads before dispatch', function() {
    $before = staging_files();
    $reply = decoded(upload_call());
    same(-32602, $reply->error->code);
    same(array(), $GLOBALS['test_mantis']['calls']);
    same($before, staging_files());
    $GLOBALS['test_mantis']['writes'] = true;
    $tools = decoded(send_request(rpc('tools/list')))->result->tools;
    $tool = end($tools);
    same('mantis_upload_attachment', $tool->name);
    same(false, $tool->annotations->readOnlyHint);
    same(false, $tool->annotations->destructiveHint);
    same(false, $tool->annotations->idempotentHint);
});

test_case('uploads the original PNG through the real native command and file_add into DATABASE', function() {
    $GLOBALS['test_mantis']['writes'] = true;
    $GLOBALS['test_mantis']['use_attachment_cache'] = true;
    $before = staging_files();
    $result = decoded(upload_call())->result;
    same(false, $result->isError);
    $file = $result->structuredContent;
    same(301, $file->attachment_id);
    same(94, $file->issue_id);
    same(0, $file->note_id);
    same(1, $file->reporter_id);
    same('IconLDC-bt3.png', $file->filename);
    same('image/png', $file->mime_type);
    same(9200, $file->size);
    same(upload_arguments()->sha256, $file->sha256);
    same(base64_decode(upload_arguments()->content_base64), $GLOBALS['test_mantis']['attachments'][301]['content']);
    check(strpos(json_encode($result), 'content_base64') === false);
    check(strpos(json_encode($result), 'mantismcp-') === false);
    same($before, staging_files());
    $download = decoded(download_call(array('attachment_id' => 301)))->result->structuredContent;
    same($file->sha256, $download->sha256);
    same(upload_arguments()->content_base64, $download->content_base64);
});

test_case('uses actual native DISK storage and cleans the moved staging file', function() {
    $GLOBALS['test_mantis']['writes'] = true;
    $directory = sys_get_temp_dir() . '/mantismcp-test-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $GLOBALS['test_mantis']['storage_dir'] = $directory;
    $GLOBALS['test_mantis']['file_method'] = DISK;
    $before = staging_files();
    $result = decoded(upload_call())->result;
    same(false, $result->isError);
    $row = $GLOBALS['test_mantis']['attachments'][$result->structuredContent->attachment_id];
    $stored = $directory . '/' . $row['diskfile'];
    check(is_file($stored));
    same(0400, fileperms($stored) & 0777);
    same(base64_decode(upload_arguments()->content_base64), file_get_contents($stored));
    same($before, staging_files());
});

test_case('keeps upload authorship and private issue access tied to each token owner', function() {
    $GLOBALS['test_mantis']['writes'] = true;
    same(true, decoded(upload_call(array(), 'bob-key'))->result->isError);
    $result = decoded(upload_call(array('issue_id' => 95), 'bob-key'))->result;
    same(false, $result->isError);
    same(2, $result->structuredContent->reporter_id);
    same(95, $result->structuredContent->issue_id);
    same(true, decoded(upload_call(array('issue_id' => 999999)))->result->isError);
});

test_case('rejects read-only issues, disabled uploads and insufficient native upload permissions', function() {
    $GLOBALS['test_mantis']['writes'] = true;
    $before = staging_files();
    $GLOBALS['test_mantis']['readonly'] = true;
    same(true, decoded(upload_call())->result->isError);
    $GLOBALS['test_mantis']['readonly'] = false;
    $GLOBALS['test_mantis']['allow_uploads'] = false;
    same(true, decoded(upload_call())->result->isError);
    $GLOBALS['test_mantis']['allow_uploads'] = true;
    $GLOBALS['test_mantis']['upload_threshold'] = 60;
    same(true, decoded(upload_call())->result->isError);
    same(300, $GLOBALS['test_mantis']['last_file_id']);
    same($before, staging_files());
});

test_case('enforces native file-type allow and deny rules before creating temporary files', function() {
    $GLOBALS['test_mantis']['writes'] = true;
    $before = staging_files();
    same(true, decoded(upload_call(array('filename' => 'blocked.svg')))->result->isError);
    $GLOBALS['test_mantis']['allowed_files'] = 'jpg';
    same(true, decoded(upload_call())->result->isError);
    same($before, staging_files());
    same(300, $GLOBALS['test_mantis']['last_file_id']);
});

test_case('preserves Unicode filenames and reports native detected MIME rather than a guessed type', function() {
    $GLOBALS['test_mantis']['writes'] = true;
    $result = decoded(upload_call(array('filename' => 'Näide-测试.png', 'mime_type' => 'application/octet-stream')))->result;
    same(false, $result->isError);
    same('Näide-测试.png', $result->structuredContent->filename);
    same('image/png', $result->structuredContent->mime_type);
});

test_case('rejects unsafe names, malformed binary and a mismatched content hash without storing files', function() {
    $GLOBALS['test_mantis']['writes'] = true;
    $before = staging_files();
    foreach (array('../file.png', '/file.png', 'C:\\file.png', "bad\nfile.png", '.', '..',
        str_repeat('é', 126) . '.png') as $name) {
        same(true, decoded(upload_call(array('filename' => $name)))->result->isError);
    }
    foreach (array('!invalid!', 'Zh==', 'Z g==') as $encoded) {
        same(true, decoded(upload_call(array('content_base64' => $encoded)))->result->isError);
    }
    same(true, decoded(upload_call(array('sha256' => str_repeat('0', 64))))->result->isError);
    same($before, staging_files());
    same(300, $GLOBALS['test_mantis']['last_file_id']);
});

test_case('rejects arbitrary file sources, reporter overrides and note targeting in the schema', function() {
    $GLOBALS['test_mantis']['writes'] = true;
    foreach (array(array('path' => '/etc/passwd'), array('url' => 'https://example.com/a.png'),
        array('reporter_id' => 2), array('note_id' => 1302), array('issue_id' => 0),
        array('mime_type' => "image/png\nBad: header"), array('content_base64' => '')) as $extra) {
        same(-32602, decoded(upload_call($extra))->error->code);
    }
    same(array(), $GLOBALS['test_mantis']['calls']);
});

test_case('enforces plugin, MantisBT and HTTP request size limits independently', function() {
    $GLOBALS['test_mantis']['writes'] = true;
    $GLOBALS['test_mantis']['max_upload_bytes'] = 1024;
    same(true, decoded(upload_call())->result->isError);
    $GLOBALS['test_mantis']['max_upload_bytes'] = 524288;
    $GLOBALS['test_mantis']['max_file_size'] = 1024;
    same(true, decoded(upload_call())->result->isError);
    $GLOBALS['test_mantis']['max_file_size'] = 5242880;
    $GLOBALS['test_mantis']['max_bytes'] = 1024;
    same(413, upload_call()->getStatusCode());
    same(300, $GLOBALS['test_mantis']['last_file_id']);
});

test_case('cleans staging files on native persistence failure and does not expose internal errors', function() {
    $GLOBALS['test_mantis']['writes'] = true;
    $before = staging_files();
    $GLOBALS['test_mantis']['storage_failure'] = 'before_insert';
    $result = decoded(upload_call())->result;
    same(true, $result->isError);
    check(strpos($result->content[0]->text, 'before retrying') !== false);
    check(strpos(json_encode($result), 'secret bytes') === false);
    same(300, $GLOBALS['test_mantis']['last_file_id']);
    same($before, staging_files());
});

test_case('reports uncertainty after a write succeeded without retrying or duplicating it', function() {
    $GLOBALS['test_mantis']['writes'] = true;
    $before = staging_files();
    $GLOBALS['test_mantis']['storage_failure'] = 'after_insert';
    $result = decoded(upload_call())->result;
    same(true, $result->isError);
    check(strpos($result->content[0]->text, 'may have completed') !== false);
    same(301, $GLOBALS['test_mantis']['last_file_id']);
    same($before, staging_files());
});

test_case('verifies stored bytes and refuses success when native stored content differs', function() {
    $GLOBALS['test_mantis']['writes'] = true;
    $GLOBALS['test_mantis']['storage_failure'] = 'corrupt';
    $before = staging_files();
    $result = decoded(upload_call())->result;
    same(true, $result->isError);
    check(!isset($result->structuredContent));
    same($before, staging_files());
});

test_case('returns native duplicate filename renaming and never claims uploads are idempotent', function() {
    $GLOBALS['test_mantis']['writes'] = true;
    $first = decoded(upload_call())->result->structuredContent;
    $second = decoded(upload_call())->result->structuredContent;
    same('IconLDC-bt3.png', $first->filename);
    same('IconLDC-bt3-2.png', $second->filename);
    check($first->attachment_id !== $second->attachment_id);
    same($first->sha256, $second->sha256);
});
