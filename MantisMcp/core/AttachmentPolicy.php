<?php
/** SPDX-License-Identifier: BSD-3-Clause */
namespace Tauria\MantisMcp;

/** Only the three public, native attachment settings are exposed. */
final class AttachmentPolicy {
    const OPTIONS = array('allowed_files', 'disallowed_files', 'max_file_size');

    public static function describe($issueId, $projectId, $data, $uploadBytes, $requestBytes) {
        require_api('utility_api.php');
        if (!isset($data->configs) || !is_array($data->configs)) {
            throw new ToolFailure('MantisBT returned an unexpected attachment policy.');
        }
        $values = array();
        foreach ($data->configs as $entry) {
            if (isset($entry->option) && in_array($entry->option, self::OPTIONS, true)
                && property_exists($entry, 'value')) {
                if (array_key_exists($entry->option, $values)) {
                    throw new ToolFailure('MantisBT returned duplicate attachment settings.');
                }
                $values[$entry->option] = $entry->value;
            }
        }
        $policy = array();
        foreach (array('allowed_files', 'disallowed_files') as $key) {
            $available = isset($values[$key]) && is_string($values[$key]);
            $raw = $available ? $values[$key] : null;
            // Native file_type_check splits on commas without trimming tokens.
            $extensions = !$available ? null : (is_blank($raw) ? array()
                : array_values(array_unique(array_map('strtolower', explode(',', $raw)))));
            $policy[$key] = array('available' => $available, 'value' => $raw, 'extensions' => $extensions);
        }
        $max = array_key_exists('max_file_size', $values) ? self::integer($values['max_file_size']) : null;
        $policy['max_file_size'] = array('available' => $max !== null, 'value' => $max);
        $known = $policy['allowed_files']['available'] && $policy['disallowed_files']['available'];
        return (object) array('issue_id' => $issueId, 'project_id' => $projectId,
            'policy' => (object) $policy,
            'rules' => (object) array('extension_policy_known' => $known,
                'extension_case_sensitive' => false, 'disallowed_takes_precedence' => true,
                'allow_other_extensions' => $known ? is_blank($policy['allowed_files']['value']) : null),
            'limits' => (object) array('plugin_upload_bytes' => AttachmentUpload::limit($uploadBytes, $max),
                'upload_limit_source' => AttachmentUpload::inherits($uploadBytes) ? 'mantis' : 'explicit',
                'configured_upload_bytes' => AttachmentUpload::inherits($uploadBytes) ? null : AttachmentUpload::limit($uploadBytes),
                'mcp_request_bytes' => Http::requestLimit($requestBytes),
                'upload_chunk_bytes' => UploadSessions::chunkLimit($requestBytes),
                'php_upload_max_filesize_bytes' => (int) ini_get_number('upload_max_filesize'),
                'php_post_max_size_bytes' => (int) ini_get_number('post_max_size'),
                'decoded_upload_upper_bound_bytes' => $max === null ? null : AttachmentUpload::effectiveLimit($uploadBytes, $max),
                'request_envelope_and_other_limits_apply' => true),
            'checks' => 'Upload filename-extension policy, not a MIME allowlist. Missing, private or invalid settings '
                . 'are unavailable, not unrestricted. Native upload checks remain authoritative. '
                . 'The assembled size follows MantisBT unless an explicit MCP cap is configured. Use chunked upload '
                . 'above the inline request budget. MCP request/chunk bounds are not assembled-file caps. '
                . 'Native MantisBT finalization still applies its PHP upload/post size checks to the completed file. '
                . 'Web-server/client/storage resources may impose other constraints. '
                . 'Upload extension rules do not prohibit downloading an existing visible attachment.');
    }

    private static function integer($value) {
        if (is_int($value)) { return $value >= 0 ? $value : null; }
        if (!is_string($value) || !preg_match('/\A[0-9]+\z/', $value)) { return null; }
        $digits = ltrim($value, '0');
        if ($digits === '') { return 0; }
        $max = (string) PHP_INT_MAX;
        return strlen($digits) < strlen($max) || (strlen($digits) === strlen($max) && strcmp($digits, $max) <= 0)
            ? (int) $digits : null;
    }
}
