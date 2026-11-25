<?php
/**
 * SPDX-License-Identifier: BSD-3-Clause
 * Copyright (c) 2026, Peeter Vois
 */

namespace Tauria\MantisMcp;

/**
 * Call MantisBT's own authenticated REST handlers in-process. No loopback HTTP,
 * upstream credential, arbitrary URL, or replacement permission model is used.
 */
final class RestApi {
    private $request;
    private $responseFactory;
    private $handlers;
    private $maxDownloadBytes;
    private $maxUploadBytes;

    public function __construct($request, ?callable $responseFactory = null, array $handlers = array(),
        $maxDownloadBytes = AttachmentDownload::DEFAULT_MAX_BYTES,
        $maxUploadBytes = AttachmentUpload::DEFAULT_MAX_BYTES) {
        $this->request = $request;
        $this->responseFactory = $responseFactory ?: function() { return new \Slim\Http\Response(); };
        $this->maxDownloadBytes = AttachmentDownload::limit($maxDownloadBytes);
        $this->maxUploadBytes = AttachmentUpload::limit($maxUploadBytes);
        $this->handlers = $handlers + array(
            'user' => 'rest_user_get_me',
            'projects' => 'rest_projects_get',
            'issues' => 'rest_issue_get',
            'files' => 'rest_issue_files_get',
            'options' => 'rest_config_get',
            'create' => 'rest_issue_add',
            'update' => 'rest_issue_update',
            'note' => 'rest_issue_note_add',
        );
    }

    public function execute($name, array $arguments) {
        switch ($name) {
            case 'mantis_get_current_user':
                return $this->call('user', 'GET', array('select' => 'id,name,access_level'));
            case 'mantis_list_projects':
                return $this->call('projects', 'GET');
            case 'mantis_get_project':
                return $this->call('projects', 'GET', array(), null,
                    array('id' => $arguments['project_id']));
            case 'mantis_get_issue_options':
                $project = isset($arguments['project_id']) ? $arguments['project_id'] : 0;
                if ($project > 0) {
                    // ConfigsGetCommand itself checks existence, not membership.
                    $this->call('projects', 'GET', array(), null, array('id' => $project));
                }
                return $this->call('options', 'GET', array(
                    'project_id' => $project,
                    'option' => array('status_enum_string', 'priority_enum_string',
                        'severity_enum_string', 'resolution_enum_string', 'view_state_enum_string'),
                ));
            case 'mantis_list_issues':
                $query = $arguments + array('page' => 1, 'page_size' => 20, 'project_id' => 0);
                return $this->call('issues', 'GET', $query);
            case 'mantis_get_issue':
                $query = isset($arguments['select']) ? array('select' => $arguments['select']) : array();
                return $this->call('issues', 'GET', $query, null, array('id' => $arguments['issue_id']));
            case 'mantis_download_attachment':
                $issueId = $arguments['issue_id'];
                $attachmentId = $arguments['attachment_id'];
                $offset = isset($arguments['offset']) ? $arguments['offset'] : 0;
                $length = isset($arguments['length']) ? $arguments['length'] : AttachmentDownload::CHUNK_BYTES;
                $expected = isset($arguments['expected_sha256']) ? $arguments['expected_sha256'] : null;
                $this->call('issues', 'GET', array('select' => 'id'), null, array('id' => $issueId));
                $info = AttachmentDownload::authorize($issueId, $attachmentId, $this->maxDownloadBytes);
                if ($offset > $info['size']) {
                    throw new ToolFailure('Download offset is beyond the end of the attachment.');
                }
                $data = $this->call('files', 'GET', array(), null,
                    array('id' => $issueId, 'file_id' => $attachmentId));
                return AttachmentDownload::chunk($issueId, $attachmentId, $info, $data,
                    $offset, $length, $expected, $this->maxDownloadBytes);
            case 'mantis_get_relationships':
                $this->call('issues', 'GET', array('select' => 'id'), null, array('id' => $arguments['issue_id']));
                return Relationships::listForIssue($arguments['issue_id']);
            case 'mantis_get_relationship_types':
                return Relationships::types();
            case 'mantis_set_relationship':
                foreach (array($arguments['issue_id'], $arguments['target_issue_id']) as $id) {
                    $this->call('issues', 'GET', array('select' => 'id'), null, array('id' => $id));
                }
                return Relationships::set($arguments['issue_id'], $arguments['target_issue_id'], $arguments['type_id']);
            case 'mantis_delete_relationship':
                $this->call('issues', 'GET', array('select' => 'id'), null, array('id' => $arguments['issue_id']));
                $target = Relationships::target($arguments['issue_id'], $arguments['relationship_id']);
                $this->call('issues', 'GET', array('select' => 'id'), null, array('id' => $target));
                return Relationships::delete($arguments['issue_id'], $arguments['relationship_id']);
            case 'mantis_upload_attachment':
                $this->call('issues', 'GET', array('select' => 'id'), null,
                    array('id' => $arguments['issue_id']));
                return AttachmentUpload::execute($arguments['issue_id'], $arguments, $this->maxUploadBytes);
            case 'mantis_create_issue':
                $payload = $this->fields($arguments);
                $payload['project'] = array('id' => $arguments['project_id']);
                $data = $this->call('create', 'POST', array(), $payload);
                return (object) array('issue' => $this->compactIssue($data->issue));
            case 'mantis_update_issue':
                $data = $this->call('update', 'PATCH', array(),
                    $this->fields((array) $arguments['changes']), array('id' => $arguments['issue_id']));
                return (object) array('issue' => $this->compactIssue($data->issues[0]));
            case 'mantis_add_note':
                $payload = array('text' => $arguments['text']);
                if (array_key_exists('private', $arguments)) {
                    $payload['view_state'] = array('name' => $arguments['private'] ? 'private' : 'public');
                }
                $data = $this->call('note', 'POST', array(), $payload,
                    array('id' => $arguments['issue_id']));
                $note = new \stdClass();
                foreach (array('id', 'reporter', 'view_state', 'created_at') as $key) {
                    if (isset($data->note->$key)) {
                        $note->$key = $data->note->$key;
                    }
                }
                return (object) array('issue_id' => $arguments['issue_id'], 'note' => $note);
        }
        throw new ToolFailure('Unknown MantisBT operation.');
    }

    private function call($handler, $method, $query = array(), $payload = null, $path = array()) {
        if (!isset($this->handlers[$handler]) || !is_callable($this->handlers[$handler])) {
            throw new ToolFailure('This MantisBT REST handler is unavailable.');
        }
        $request = $this->request->withMethod($method)->withQueryParams($query)->withParsedBody($payload)
            ->withoutHeader('If-Match')->withoutHeader('If-None-Match')
            ->withoutHeader('If-Modified-Since');
        $response = call_user_func($this->handlers[$handler], $request,
            call_user_func($this->responseFactory), $path);
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            $messages = array(
                400 => 'MantisBT rejected the supplied values.',
                401 => 'MantisBT authentication is required.',
                403 => 'Your MantisBT account is not allowed to perform this operation.',
                404 => 'The item does not exist or is not accessible to your MantisBT account.',
                409 => 'The requested change conflicts with the current MantisBT state.',
                412 => 'The issue changed; read it again before retrying.',
            );
            throw new ToolFailure(isset($messages[$status])
                ? $messages[$status] : 'MantisBT could not complete the operation.');
        }
        $data = json_decode((string) $response->getBody());
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new ToolFailure('MantisBT returned an unexpected response.');
        }
        return $data;
    }

    private function fields(array $arguments) {
        $result = array();
        foreach (array('summary', 'description') as $field) {
            if (array_key_exists($field, $arguments)) {
                $result[$field] = $arguments[$field];
            }
        }
        foreach (array('category', 'handler', 'status', 'priority', 'severity',
            'resolution', 'view_state') as $field) {
            if (array_key_exists($field . '_id', $arguments)) {
                $result[$field] = array('id' => $arguments[$field . '_id']);
            }
        }
        return $result;
    }

    private function compactIssue($issue) {
        $result = new \stdClass();
        foreach (array('id', 'summary', 'project', 'status', 'handler', 'updated_at') as $field) {
            if (isset($issue->$field)) {
                $result->$field = $issue->$field;
            }
        }
        return $result;
    }
}
