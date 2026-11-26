<?php
/** SPDX-License-Identifier: BSD-3-Clause */
// Local test subprocess only; never included in the installable plugin.
$lock = fopen($argv[1], 'r+b');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { exit(2); }
echo "locked\n"; flush();
$read = array(STDIN); $write = null; $except = null;
// Bound a failed parent's wait so a regression cannot hang the test runner.
if (stream_select($read, $write, $except, 5) > 0) { fgets(STDIN); }
flock($lock, LOCK_UN); fclose($lock);
