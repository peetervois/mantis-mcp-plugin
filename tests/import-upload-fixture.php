<?php
/** SPDX-License-Identifier: BSD-3-Clause */
require __DIR__ . '/bootstrap.php';
reset_state();
$GLOBALS['test_mantis']['writes'] = true;
$payload = json_decode(stream_get_contents(STDIN));
$result = json_decode((string) send_request(tool_call('mantis_upload_attachment', $payload))->getBody())->result;
if ($result->isError) {
    fwrite(STDERR, $result->content[0]->text . "\n");
    exit(1);
}
$receipt = $result->structuredContent;
$bytes = ''; $offset = 0;
do {
    $download = decoded(send_request(tool_call('mantis_download_attachment', (object) array(
        'issue_id' => $payload->issue_id, 'attachment_id' => $receipt->attachment_id,
        'offset' => $offset, 'expected_sha256' => $receipt->sha256))))->result;
    if ($download->isError) { fwrite(STDERR, "Fixture download failed.\n"); exit(1); }
    $bytes .= base64_decode($download->structuredContent->content_base64, true);
    $offset = $download->structuredContent->next_offset;
} while (!$download->structuredContent->complete);
if ($bytes !== base64_decode($payload->content_base64, true)
    || hash('sha256', $bytes) !== $receipt->sha256) {
    fwrite(STDERR, "Fixture byte-for-byte round trip failed.\n"); exit(1);
}
echo json_encode($result->structuredContent);
