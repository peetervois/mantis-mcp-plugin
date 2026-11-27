<?php
/** SPDX-License-Identifier: BSD-3-Clause */
function policy_call($extra = array(), $token = 'alice-key') {
    return management_call('mantis_get_attachment_policy', $extra + array('issue_id' => 94), $token);
}

test_case('discovers attachment policy read-only through the actual native configuration command', function() {
    $result = policy_call()->result;
    same(false, $result->isError);
    $p = $result->structuredContent;
    same(94, $p->issue_id); same(1, $p->project_id);
    same(true, $p->policy->allowed_files->available);
    same('', $p->policy->allowed_files->value);
    same(array(), $p->policy->allowed_files->extensions);
    same(array('svg'), $p->policy->disallowed_files->extensions);
    same(true, $p->rules->extension_policy_known);
    same(true, $p->rules->allow_other_extensions);
    $calls = $GLOBALS['test_mantis']['calls'];
    same('issues', $calls[0]['name']); same('options', $calls[1]['name']);
    same(array('project_id' => 1, 'option' => array('allowed_files', 'disallowed_files', 'max_file_size')), $calls[1]['query']);
    same(null, $calls[1]['payload']);
});

test_case('policy is scoped to the accessible issue project and rejects hidden issue configuration access', function() {
    $GLOBALS['test_mantis']['project_configs'][1] = array('allowed_files' => 'PDF,zip', 'max_file_size' => 2048);
    $GLOBALS['g_project_override'] = 999;
    $p = policy_call()->result->structuredContent;
    same('PDF,zip', $p->policy->allowed_files->value);
    same(array('pdf', 'zip'), $p->policy->allowed_files->extensions);
    same(2048, $p->limits->decoded_upload_upper_bound_bytes);
    same(999, $GLOBALS['g_project_override']);
    $GLOBALS['test_mantis']['calls'] = array();
    same(true, policy_call(array('issue_id' => 95))->result->isError);
    same(1, count($GLOBALS['test_mantis']['calls']));
    same('issues', $GLOBALS['test_mantis']['calls'][0]['name']);
});

test_case('policy preserves native extension token semantics including spaces and empty tokens', function() {
    $GLOBALS['test_mantis']['allowed_files'] = 'PDF, zip,pdf,';
    $GLOBALS['test_mantis']['disallowed_files'] = 'PDF,EXE';
    $p = policy_call()->result->structuredContent;
    same(array('pdf', ' zip', ''), $p->policy->allowed_files->extensions);
    same(array('pdf', 'exe'), $p->policy->disallowed_files->extensions);
    same(false, $p->rules->allow_other_extensions);
    same(false, $p->rules->extension_case_sensitive);
    same(true, $p->rules->disallowed_takes_precedence);
    same(false, file_type_check('file.PdF'));
    same(false, file_type_check('file.zip'));
    same(true, file_type_check('file'));
});

test_case('missing or private policy fields remain unavailable rather than meaning unrestricted uploads', function() {
    $GLOBALS['test_mantis']['private_configs'] = array('disallowed_files', 'max_file_size');
    $GLOBALS['test_mantis']['undefined_configs'] = array('allowed_files');
    $p = policy_call()->result->structuredContent;
    foreach (array('allowed_files', 'disallowed_files', 'max_file_size') as $key) {
        same(false, $p->policy->$key->available);
        same(null, $p->policy->$key->value);
    }
    same(null, $p->policy->allowed_files->extensions);
    same(null, $p->rules->allow_other_extensions);
    same(false, $p->rules->extension_policy_known);
    same(null, $p->limits->decoded_upload_upper_bound_bytes);
});

test_case('policy shares the uploader and HTTP limit calculations without claiming the request envelope fits', function() {
    $GLOBALS['test_mantis']['max_upload_bytes'] = 9000000;
    $GLOBALS['test_mantis']['max_bytes'] = 8388608;
    $p = policy_call()->result->structuredContent;
    same(9000000, $p->limits->plugin_upload_bytes);
    same(8388608, $p->limits->mcp_request_bytes);
    same(2097152, $p->limits->php_upload_max_filesize_bytes);
    same(2097152, $p->limits->decoded_upload_upper_bound_bytes);
    same(true, $p->limits->request_envelope_and_other_limits_apply);
    $GLOBALS['test_mantis']['max_file_size'] = 1024;
    same(1024, policy_call()->result->structuredContent->limits->decoded_upload_upper_bound_bytes);
});

test_case('policy normalizes native decimal size strings and marks invalid or overflowing sizes unavailable', function() {
    foreach (array('0002048' => 2048, '0' => 0, '-1' => null, '2MB' => null,
        '999999999999999999999999999' => null) as $value => $expected) {
        $GLOBALS['test_mantis']['max_file_size'] = (string) $value;
        $p = policy_call()->result->structuredContent;
        same($expected, $p->policy->max_file_size->value);
        same($expected !== null, $p->policy->max_file_size->available);
    }
});

test_case('policy rejects unbounded configuration requests and user, URL or project overrides in its schema', function() {
    foreach (array(array('option' => 'smtp_password'), array('user_id' => 2), array('project_id' => 2),
        array('url' => 'https://example.com'), array('issue_id' => 0)) as $extra) {
        same(-32602, policy_call($extra)->error->code);
    }
    same(array(), $GLOBALS['test_mantis']['calls']);
});

function generic_file($bytes, $name = 'events.LOG', $mime = 'text/plain') {
    return array('filename' => $name, 'mime_type' => $mime,
        'content_base64' => base64_encode($bytes), 'sha256' => hash('sha256', $bytes));
}

test_case('native generic file rules and bytes apply to issues, new notes and existing private notes', function() {
    $GLOBALS['test_mantis']['writes'] = true;
    $GLOBALS['test_mantis']['allowed_files'] = 'log,txt,zip,pdf,bin';
    $GLOBALS['test_mantis']['disallowed_files'] = 'pdf';
    $bytes = "2026-01-01 event log\n" . implode('', array_map('chr', range(0, 255)));
    $file = generic_file($bytes, 'sample.BIN', 'application/octet-stream');
    foreach (array('issue', 'new-note', 'existing-note') as $mode) {
        if ($mode === 'new-note') { $result = new_note_file(array('issue_id' => 94, 'attachment' => (object) $file))->result; }
        else { $result = decoded(upload_call($file + ($mode === 'existing-note' ? array('note_id' => 1302) : array())))->result; }
        same(false, $result->isError);
        $receipt = $mode === 'new-note' ? $result->structuredContent->attachment : $result->structuredContent;
        $d = decoded(download_call(array('attachment_id' => $receipt->attachment_id)))->result->structuredContent;
        same($bytes, base64_decode($d->content_base64)); same($file['sha256'], $d->sha256);
        if ($mode === 'existing-note') { same(1302, $d->note_id); }
    }
    same(true, decoded(upload_call(generic_file('%PDF-1.4 test', 'blocked.PDF', 'application/pdf')))->result->isError);
    $GLOBALS['test_mantis']['allowed_files'] = '';
    same(false, decoded(upload_call(generic_file('Plain log content', 'trace.LOG')))->result->isError);
});

test_case('upload extension exclusions do not block downloading an existing visible non-image file', function() {
    $GLOBALS['test_mantis']['writes'] = true;
    $file = generic_file('PDF-style bytes for exact storage', 'document.PDF', 'application/pdf');
    $uploaded = decoded(upload_call($file))->result->structuredContent;
    $GLOBALS['test_mantis']['disallowed_files'] = 'pdf';
    same(false, file_type_check('document.PDF'));
    $d = decoded(download_call(array('attachment_id' => $uploaded->attachment_id)))->result;
    same(false, $d->isError); same($file['content_base64'], $d->structuredContent->content_base64);
});

test_case('smoke validates discovered policy semantics and cannot mistake unavailable fields for empty values', function() {
    foreach (array('unavailable', 'precedence', 'upper-bound') as $case) {
        smoke_fails(function() use ($case) { smoke_fixture(function($r, $role, $method, $params) use ($case) {
            if ($method === 'tools/call' && $params->name === 'mantis_get_attachment_policy'
                && empty($r[1]->result->isError)) {
                $p = $r[1]->result->structuredContent;
                if ($case === 'unavailable') { $p->policy->allowed_files->available = false; }
                if ($case === 'precedence') { $p->rules->disallowed_takes_precedence = false; }
                if ($case === 'upper-bound') { $p->limits->decoded_upload_upper_bound_bytes += 1; }
            }
            return $r;
        }, array('issue_id' => 94)); });
    }
});
