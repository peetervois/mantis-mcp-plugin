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
echo json_encode($result->structuredContent);
