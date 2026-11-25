<?php
/**
 * Emit test-only chunks from the real MCP dispatcher for the client contract test.
 * SPDX-License-Identifier: BSD-3-Clause
 * Copyright (c) 2026, Peeter Vois
 */

require __DIR__ . '/bootstrap.php';
reset_state();
$bytes = file_get_contents(dirname(__DIR__) . '/chatgpt/tauria-tasks/assets/IconLDC-bt3.png');
$GLOBALS['test_mantis']['attachments'][219]['bytes'] = $bytes;
$GLOBALS['test_mantis']['attachments'][219]['filesize'] = strlen($bytes);
$GLOBALS['test_mantis']['attachments'][219]['filename'] = 'IconLDC-bt3.png';
$GLOBALS['test_mantis']['attachments'][219]['file_type'] = 'image/png';
$chunks = array();
$offset = 0;
$hash = null;
do {
    $arguments = array('issue_id' => 94, 'attachment_id' => 219, 'offset' => $offset, 'length' => 1024);
    if ($hash !== null) {
        $arguments['expected_sha256'] = $hash;
    }
    $response = send_request(tool_call('mantis_download_attachment', (object) $arguments));
    $reply = decoded($response);
    if ($response->getStatusCode() !== 200 || !isset($reply->result)
        || $reply->result->isError || !isset($reply->result->structuredContent)) {
        fwrite(STDERR, "The fixture MCP request failed.\n");
        exit(1);
    }
    $chunk = $reply->result->structuredContent;
    $chunks[] = $chunk;
    $offset = $chunk->next_offset;
    $hash = $chunk->sha256;
} while (!$chunk->complete);
echo json_encode($chunks);
