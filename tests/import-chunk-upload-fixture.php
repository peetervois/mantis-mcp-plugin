<?php
/** Local client contract fixture; no network or production credentials. SPDX-License-Identifier: BSD-3-Clause */
require __DIR__ . '/bootstrap.php';
reset_state();
$GLOBALS['test_mantis']['writes'] = true;
$GLOBALS['test_mantis']['max_upload_bytes'] = null;
$GLOBALS['test_mantis']['max_file_size'] = 8388608;
$GLOBALS['test_mantis']['max_download_bytes'] = 8388608;
$GLOBALS['test_mantis']['php_limits'] = array('upload_max_filesize' => 8388608, 'post_max_size' => 8388608);
$GLOBALS['test_mantis_session_url'] = 'https://stream-fixture.invalid/' . bin2hex(random_bytes(12)) . '/';

function client_fixture_call($name, $args) {
    $r = decoded(send_request(tool_call($name, (object) $args)))->result;
    if ($r->isError) { fwrite(STDERR, $r->content[0]->text . "\n"); exit(1); }
    return $r->structuredContent;
}
function fixture_reply($data) { echo json_encode($data) . "\n"; flush(); }

$manifest = json_decode(fgets(STDIN), true);
$begin = client_fixture_call('mantis_begin_attachment_upload', $manifest);
$id = $begin->upload_id;
fixture_reply($begin);
while (($line = fgets(STDIN)) !== false) {
    $args = json_decode($line, true);
    if (isset($args['finish'])) {
        $receipt = client_fixture_call('mantis_finish_attachment_upload', array('upload_id' => $id));
        $context = hash_init('sha256'); $size = 0; $offset = 0;
        do {
            $d = client_fixture_call('mantis_download_attachment', array('issue_id' => $manifest['issue_id'],
                'attachment_id' => $receipt->attachment_id, 'offset' => $offset, 'expected_sha256' => $manifest['sha256']));
            $bytes = base64_decode($d->content_base64, true);
            hash_update($context, $bytes); $size += strlen($bytes); $offset = $d->next_offset;
        } while (!$d->complete);
        if ($size !== $manifest['size'] || hash_final($context) !== $manifest['sha256']) {
            fwrite(STDERR, "Chunked upload/download contract mismatch.\n"); exit(1);
        }
        $receipt->round_trip_verified = true;
        fixture_reply($receipt);
        client_fixture_call('mantis_delete_attachment', array('issue_id' => $manifest['issue_id'], 'attachment_id' => $receipt->attachment_id));
        break;
    }
    fixture_reply(client_fixture_call('mantis_upload_attachment_chunk', $args));
}
$root = sys_get_temp_dir() . '/.mantis-mcp-uploads-' . substr(hash('sha256', config_get_global('path')), 0, 20);
if (is_dir($root)) {
    foreach (new DirectoryIterator($root) as $file) { if ($file->isFile() || $file->isLink()) { unlink($file->getPathname()); } }
    rmdir($root);
}
