<?php
/**
 * SPDX-License-Identifier: BSD-3-Clause
 * Copyright (c) 2026, Peeter Vois
 */

namespace Tauria\MantisMcp;

/**
 * An explicit allow-list: callers cannot choose REST functions, URLs or config keys.
 */
final class Tools {
    private $definitions;

    public function __construct($enableWrites = false) {
        $id = array('type' => 'integer', 'minimum' => 1);
        $project = array('type' => 'integer', 'minimum' => 0,
            'description' => 'MantisBT project id; 0 means all accessible projects.');
        $select = array('type' => 'string', 'minLength' => 1, 'maxLength' => 512,
            'pattern' => '^[a-zA-Z_][a-zA-Z0-9_]*(,[a-zA-Z_][a-zA-Z0-9_]*)*$',
            'description' => 'Optional comma-separated MantisBT field names, such as id,summary,status.');

        $this->definitions = array(
            self::tool('mantis_get_current_user', 'Get the account represented by this API token.',
                array(), array()),
            self::tool('mantis_list_projects', 'List only projects accessible to the current account.',
                array(), array()),
            self::tool('mantis_get_project', 'Read project details, including categories and versions.',
                array('project_id' => $id), array('project_id')),
            self::tool('mantis_get_issue_options',
                'Get project-specific status, priority, severity, resolution and view-state enum ids.',
                array('project_id' => $project), array()),
            self::tool('mantis_list_issues', 'List accessible issues using MantisBT filters and pagination.',
                array(
                    'project_id' => $project,
                    'page' => array('type' => 'integer', 'minimum' => 1),
                    'page_size' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 100),
                    'filter_id' => array('type' => 'string', 'minLength' => 1, 'maxLength' => 64,
                        'description' => 'An existing MantisBT saved filter id or standard filter name.'),
                    'select' => $select,
                ), array()),
            self::tool('mantis_get_issue', 'Read an accessible issue, including permitted notes.',
                array('issue_id' => $id, 'select' => $select), array('issue_id')),
            self::tool('mantis_get_workflow',
                'Inspect the effective workflow graph and current-user status thresholds for an accessible issue. '
                . 'Read this before changing status; discover intermediate states instead of guessing transitions. '
                . 'Native required-field and read-only/reopen rules still apply.',
                array('issue_id' => $id), array('issue_id')),
            self::tool('mantis_get_relationship_types',
                'Discover native relationship type ids, API names, labels and reverse-direction type ids, including custom types.',
                array(), array()),
            self::tool('mantis_get_relationships',
                'List this issue\'s relationships whose other issue is accessible to the current account. Types are relative to this issue.',
                array('issue_id' => $id), array('issue_id')),
            self::tool('mantis_download_attachment',
                'Download original attachment bytes in bounded base64 chunks through this connection. '
                . 'Read content_base64 from structuredContent using code; do not print it into chat. '
                . 'Start at offset 0, then pass next_offset and expected_sha256 from the first response. '
                . 'Decode and concatenate chunks, verify total size and SHA-256, and save using filename. '
                . 'Use attachment ids from mantis_get_issue. For issue analysis, download relevant screenshots '
                . 'and inspect the saved images with a vision-capable viewer. Issue and private-note permissions apply.',
                array('issue_id' => $id, 'attachment_id' => $id,
                    'offset' => array('type' => 'integer', 'minimum' => 0),
                    'length' => array('type' => 'integer', 'minimum' => 1,
                        'maximum' => AttachmentDownload::CHUNK_BYTES),
                    'expected_sha256' => array('type' => 'string', 'pattern' => '^[a-f0-9]{64}$',
                        'description' => 'Required when offset is greater than zero; pins all chunks to the same file.')),
                array('issue_id', 'attachment_id')),
        );
        $this->definitions[count($this->definitions) - 1]['outputSchema'] = self::downloadSchema();

        if ($enableWrites) {
            $fields = array(
                'summary' => array('type' => 'string', 'minLength' => 1, 'maxLength' => 128),
                'description' => array('type' => 'string', 'minLength' => 1, 'maxLength' => 65535),
                'category_id' => $id,
                'handler_id' => array('type' => 'integer', 'minimum' => 0,
                    'description' => 'Assignee id; 0 unassigns the issue.'),
                'status_id' => $id,
                'priority_id' => $id,
                'severity_id' => $id,
                'resolution_id' => $id,
                'view_state_id' => $id,
            );
            $create = $fields;
            unset($create['status_id'], $create['resolution_id']);
            $create['project_id'] = $id;
            $this->definitions[] = self::tool('mantis_create_issue',
                'Create an issue as the current account. Discover project/category and enum ids first.',
                $create, array('project_id', 'category_id', 'summary', 'description'), true, false);
            $this->definitions[] = self::tool('mantis_update_issue',
                'Change an accessible issue using the current account and normal MantisBT workflow rules. '
                . 'Before status changes, inspect mantis_get_workflow and follow configured transitions.',
                array('issue_id' => $id,
                    'changes' => self::objectSchema($fields, array(), 1)),
                array('issue_id', 'changes'), true, true);
            $this->definitions[] = self::tool('mantis_add_note',
                'Add a note as the current account. Set private=true only when a private note is intended.',
                array('issue_id' => $id,
                    'text' => array('type' => 'string', 'minLength' => 1, 'maxLength' => 65535),
                    'private' => array('type' => 'boolean')),
                array('issue_id', 'text'), true, false);
            $this->definitions[] = self::tool('mantis_set_relationship',
                'Create or change a native relationship between two accessible issues. Discover types first. '
                . 'An existing link between the pair is updated, so read existing relationships before replacing its type.',
                array('issue_id' => $id, 'target_issue_id' => $id,
                    'type_id' => array('type' => 'integer', 'minimum' => 0)),
                array('issue_id', 'target_issue_id', 'type_id'), true, true);
            $this->definitions[] = self::tool('mantis_delete_relationship',
                'Delete the specified native relationship only when requested by the user. Both endpoint issues must be accessible. '
                . 'Discover the relationship id and direction first.',
                array('issue_id' => $id, 'relationship_id' => $id), array('issue_id', 'relationship_id'), true, true);
            $this->definitions[] = self::tool('mantis_delete_attachment',
                'Permanently delete one attachment only on an explicit user request or as cleanup of a disposable test file '
                . 'created for a user-authorized test. Read its issue and metadata first. Issue ownership, note visibility, '
                . 'read-only state and native delete permissions apply. This cannot be undone.',
                array('issue_id' => $id, 'attachment_id' => $id), array('issue_id', 'attachment_id'), true, true);
            $this->definitions[] = self::tool('mantis_upload_attachment',
                'Attach one user-supplied file to an existing accessible issue as the token owner. '
                . 'Use code to read the original local file, compute SHA-256 and base64-encode its bytes; '
                . 'do not invent file content or print encoded data in chat. The default decoded limit is '
                . '512 KiB; server, PHP and MantisBT limits also apply. The server cannot read client paths or URLs. '
                . 'Returns verified stored-file metadata, including an attachment id for download/readback. '
                . 'Native MantisBT may rename duplicate filenames. This write is not idempotent: after a timeout '
                . 'or uncertain result, read the issue before deciding whether to retry. Does not modify existing notes.',
                array('issue_id' => $id,
                    'filename' => array('type' => 'string', 'minLength' => 1, 'maxLength' => 250),
                    'mime_type' => array('type' => 'string', 'minLength' => 3, 'maxLength' => 127,
                        'pattern' => '^[a-zA-Z0-9!#$&^_.+\-]+/[a-zA-Z0-9!#$&^_.+\-]+$'),
                    'content_base64' => array('type' => 'string', 'minLength' => 4,
                        'maxLength' => AttachmentUpload::MAX_ENCODED_BYTES),
                    'sha256' => array('type' => 'string', 'pattern' => '^[a-f0-9]{64}$')),
                array('issue_id', 'filename', 'mime_type', 'content_base64', 'sha256'), true, false);
            $receipt = array();
            foreach (array('issue_id', 'attachment_id', 'note_id', 'reporter_id', 'size') as $field) {
                $receipt[$field] = array('type' => 'integer', 'minimum' => 0);
            }
            foreach (array('filename', 'mime_type', 'sha256') as $field) {
                $receipt[$field] = array('type' => 'string');
            }
            $this->definitions[count($this->definitions) - 1]['outputSchema'] =
                self::objectSchema($receipt, array_keys($receipt));
        }
    }

    public function definitions() {
        return $this->definitions;
    }

    public function validate($name, $arguments) {
        foreach ($this->definitions as $definition) {
            if ($definition['name'] === $name) {
                self::validateValue($arguments, $definition['inputSchema'], 'arguments');
                if ($name === 'mantis_download_attachment' && isset($arguments->offset)
                    && $arguments->offset > 0 && !isset($arguments->expected_sha256)) {
                    throw new ProtocolError('expected_sha256 is required for a resumed download.', -32602);
                }
                return;
            }
        }
        throw new ProtocolError('Unknown or disabled tool.', -32602);
    }

    private static function tool($name, $description, $properties, $required,
        $write = false, $destructive = false) {
        return array(
            'name' => $name, 'description' => $description,
            'inputSchema' => self::objectSchema($properties, $required),
            'annotations' => array(
                'readOnlyHint' => !$write,
                'destructiveHint' => $destructive,
                'idempotentHint' => !$write,
                'openWorldHint' => false,
            ),
        );
    }

    private static function downloadSchema() {
        $properties = array();
        foreach (array('issue_id', 'attachment_id', 'note_id', 'size', 'offset', 'bytes_returned') as $key) {
            $properties[$key] = array('type' => 'integer', 'minimum' => 0);
        }
        foreach (array('filename', 'mime_type', 'sha256', 'content_base64') as $key) {
            $properties[$key] = array('type' => 'string');
        }
        $properties['next_offset'] = array('type' => array('integer', 'null'));
        $properties['complete'] = array('type' => 'boolean');
        return self::objectSchema($properties, array_keys($properties));
    }

    private static function objectSchema($properties, $required, $minimum = 0) {
        return array('type' => 'object', 'properties' => (object) $properties,
            'required' => $required, 'additionalProperties' => false, 'minProperties' => $minimum);
    }

    private static function validateValue($value, $schema, $path) {
        $valid = true;
        switch ($schema['type']) {
            case 'object':
                $valid = $value instanceof \stdClass;
                if ($valid) {
                    $properties = (array) $schema['properties'];
                    foreach ($schema['required'] as $required) {
                        if (!property_exists($value, $required)) {
                            throw new ProtocolError($path . '.' . $required . ' is required.', -32602);
                        }
                    }
                    if (count((array) $value) < $schema['minProperties']) {
                        throw new ProtocolError($path . ' must not be empty.', -32602);
                    }
                    foreach ((array) $value as $key => $item) {
                        if (!array_key_exists($key, $properties)) {
                            throw new ProtocolError($path . ' contains an unsupported field.', -32602);
                        }
                        self::validateValue($item, $properties[$key], $path . '.' . $key);
                    }
                }
                break;
            case 'integer':
                $valid = is_int($value)
                    && (!isset($schema['minimum']) || $value >= $schema['minimum'])
                    && (!isset($schema['maximum']) || $value <= $schema['maximum']);
                break;
            case 'boolean':
                $valid = is_bool($value);
                break;
            case 'string':
                $valid = is_string($value);
                if ($valid) {
                    $length = preg_match_all('/./us', $value);
                    $valid = $length !== false
                        && (!isset($schema['minLength']) || $length >= $schema['minLength'])
                        && (!isset($schema['maxLength']) || $length <= $schema['maxLength'])
                        && (!isset($schema['pattern']) || preg_match('~' . $schema['pattern'] . '~uD', $value) === 1);
                }
                break;
        }
        if (!$valid) {
            throw new ProtocolError($path . ' has an invalid value or type.', -32602);
        }
    }
}
