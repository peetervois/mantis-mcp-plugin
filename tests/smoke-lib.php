<?php
/** SPDX-License-Identifier: BSD-3-Clause */
namespace Tauria\MantisMcp\Smoke;

/** Pure response checks shared by the CLI, simulated transports and MCP captures. */
final class Checks {
    const READS = array('mantis_get_current_user', 'mantis_list_projects', 'mantis_get_project',
        'mantis_get_issue_options', 'mantis_list_issues', 'mantis_get_issue', 'mantis_get_workflow',
        'mantis_get_relationship_types', 'mantis_get_relationships', 'mantis_download_attachment');
    const WRITES = array('mantis_create_issue', 'mantis_update_issue', 'mantis_add_note',
        'mantis_set_relationship', 'mantis_delete_relationship', 'mantis_delete_attachment',
        'mantis_upload_attachment');
    private $request;
    private $report;
    private $skipped = 0;

    public function __construct(callable $request, callable $report) {
        $this->request = $request;
        $this->report = $report;
    }

    public static function ensure($condition, $label) {
        if (!$condition) { throw new \RuntimeException($label . ' failed.'); }
    }

    private function say($message) { call_user_func($this->report, $message); }
    private function skip($message) { ++$this->skipped; $this->say('SKIP ' . $message); }

    public function run(array $options = array()) {
        foreach (array('missing', 'invalid') as $role) {
            $r = call_user_func($this->request, $role, 'tools/list', new \stdClass());
            self::ensure($r[0] === 401, 'Missing/invalid-token rejection');
        }
        $this->say('PASS missing and invalid tokens rejected');
        $primary = $this->session('primary', $options);
        if (!empty($options['secondary'])) {
            $secondary = $this->session('secondary', isset($options['secondary_options']) ? $options['secondary_options'] : array());
            self::ensure($secondary !== $primary, 'Separate accounts must have different identities');
            self::ensure($this->tool('primary', 'mantis_get_current_user')->id === $primary, 'Primary account isolation');
            $this->say('PASS separate account identities and primary-account readback');
        } else { $this->skip('separate account checks: no second token supplied'); }
        $this->say('Read-only checks completed; ' . $this->skipped . ' optional groups skipped.');
    }

    private function rpc($role, $method, $params) {
        $r = call_user_func($this->request, $role, $method, $params);
        self::ensure($r[0] === 200 && $r[1] instanceof \stdClass && isset($r[1]->jsonrpc)
            && $r[1]->jsonrpc === '2.0'
            && !isset($r[1]->error) && isset($r[1]->result), 'JSON-RPC response');
        return $r[1]->result;
    }

    private function tool($role, $name, array $args = array()) {
        self::ensure(in_array($name, array_merge(self::READS, array('mantis_get_attachment_policy')), true), 'Read-only call allowlist');
        $r = $this->rpc($role, 'tools/call', (object) array('name' => $name, 'arguments' => (object) $args));
        self::ensure(isset($r->isError) && $r->isError === false
            && isset($r->structuredContent) && $r->structuredContent instanceof \stdClass, 'Successful structured tool response');
        return $r->structuredContent;
    }

    private function session($role, array $options) {
        $init = $this->rpc($role, 'initialize', (object) array('protocolVersion' => '2025-06-18',
            'capabilities' => new \stdClass(), 'clientInfo' => (object) array('name' => 'mantis-mcp-smoke', 'version' => '0.2.0')));
        self::ensure(isset($init->serverInfo->name, $init->serverInfo->version, $init->protocolVersion)
            && $init->serverInfo->name === 'mantis-mcp-plugin' && is_string($init->serverInfo->version)
            && $init->serverInfo->version !== '' && isset($init->capabilities->tools)
            && in_array($init->protocolVersion, array('2025-03-26', '2025-06-18', '2025-11-25'), true), 'Initialize contract');
        $version = preg_match('/\A[a-zA-Z0-9.+_-]{1,64}\z/', $init->serverInfo->version)
            ? $init->serverInfo->version : '[nonstandard version label]';
        $this->say('INFO ' . $role . ' server version ' . $version . '; compatibility is checked by capabilities');
        $ready = call_user_func($this->request, $role, 'notifications/initialized', new \stdClass());
        self::ensure($ready[0] === 202, 'Initialized notification');
        $this->rpc($role, 'ping', new \stdClass());
        $inventory = $this->rpc($role, 'tools/list', new \stdClass());
        self::ensure(isset($inventory->tools) && is_array($inventory->tools)
            && empty($inventory->nextCursor), 'Complete tool discovery');
        $names = self::inventory($inventory->tools, isset($options['writes']) ? $options['writes'] : 'auto');
        $this->say('PASS ' . $role . ' tool names, schemas and annotations (' . count($names) . ' tools)');
        $identity = $this->tool($role, 'mantis_get_current_user');
        self::ensure(isset($identity->id) && is_int($identity->id) && $identity->id > 0, 'Account identity');
        if (isset($options['expected_user_id'])) {
            self::ensure($identity->id === $options['expected_user_id'], 'Expected token owner');
        }
        $this->say('PASS ' . $role . ' account id ' . $identity->id);
        $types = self::types($this->tool($role, 'mantis_get_relationship_types'));
        $this->say('PASS ' . $role . ' relationship types and complementary definitions');
        if (isset($options['issue_id'])) {
            $id = $options['issue_id'];
            $before = $this->issue($role, $id);
            self::workflow($this->tool($role, 'mantis_get_workflow', array('issue_id' => $id)), $before);
            $links = self::relationships($this->tool($role, 'mantis_get_relationships', array('issue_id' => $id)), $id, $types);
            $limit = isset($options['max_peers']) ? $options['max_peers'] : 20;
            foreach (array_slice($links, 0, $limit) as $link) {
                $this->issue($role, $link->target_issue_id);
                $reverse = self::relationships($this->tool($role, 'mantis_get_relationships', array('issue_id' => $link->target_issue_id)), $link->target_issue_id, $types);
                $found = array_filter($reverse, function($r) use ($link, $id, $types) {
                    return $r->id === $link->id && $r->target_issue_id === $id
                        && $r->type->id === $types[$link->type->id]->complementary_type_id;
                });
                self::ensure(count($found) === 1, 'Reverse relationship direction');
            }
            if (count($links) > $limit) { $this->skip('additional reverse-link checks beyond configured peer limit'); }
            if (in_array('mantis_get_attachment_policy', $names, true)) {
                $policy = $this->tool($role, 'mantis_get_attachment_policy', array('issue_id' => $id));
                self::policy($policy, $before);
            } else { $this->skip($role . ' attachment-policy check: tool not advertised'); }
            $after = $this->issue($role, $id);
            self::ensure($before->status->id === $after->status->id && $before->updated_at === $after->updated_at,
                'Issue unchanged during read-only checks (or concurrent edit detected)');
            $this->say('PASS ' . $role . ' workflow consistency, visible relationships and unchanged issue');
        } else { $this->skip($role . ' workflow/relationship issue checks: no accessible issue id supplied'); }
        if (isset($options['hidden_issue_id'])) {
            foreach (array('mantis_get_issue', 'mantis_get_workflow', 'mantis_get_relationships') as $name) {
                $this->denied($role, $name, $options['hidden_issue_id']);
            }
            if (in_array('mantis_get_attachment_policy', $names, true)) {
                $this->denied($role, 'mantis_get_attachment_policy', $options['hidden_issue_id']);
            }
            $this->say('PASS ' . $role . ' inaccessible issue denied by each issue inspection tool');
        } else { $this->skip($role . ' access-denial checks: no inaccessible issue id supplied'); }
        return $identity->id;
    }

    private function issue($role, $id) {
        $d = $this->tool($role, 'mantis_get_issue', array('issue_id' => $id, 'select' => 'id,project,status,updated_at'));
        self::ensure(isset($d->issues) && is_array($d->issues) && count($d->issues) === 1, 'Single issue response');
        $issue = $d->issues[0];
        self::ensure(isset($issue->id, $issue->project->id, $issue->status->id, $issue->updated_at)
            && $issue->id === $id && is_int($issue->project->id) && $issue->project->id > 0
            && is_int($issue->status->id) && is_string($issue->updated_at) && $issue->updated_at !== '', 'Issue context');
        return $issue;
    }

    private function denied($role, $name, $id) {
        $r = call_user_func($this->request, $role, 'tools/call', (object) array('name' => $name, 'arguments' => (object) array('issue_id' => $id)));
        self::ensure($r[0] === 200 && isset($r[1]->result), 'Access-denial response');
        $v = $r[1]->result;
        $messages = array('The item does not exist or is not accessible to your MantisBT account.',
            'Your MantisBT account is not allowed to perform this operation.');
        self::ensure(isset($v->isError) && $v->isError === true && isset($v->content) && is_array($v->content), 'Access denial');
        $texts = array_filter($v->content, function($c) use ($messages) {
            return isset($c->type, $c->text) && $c->type === 'text' && in_array($c->text, $messages, true);
        });
        $extra = isset($v->structuredContent) ? array_diff(array_keys((array) $v->structuredContent), array('error_code')) : array();
        self::ensure(count($texts) === 1 && count($v->content) === 1 && !$extra, 'Recognized permission denial without issue data');
    }

    public static function inventory(array $tools, $writes = 'auto') {
        self::ensure(in_array($writes, array('auto', 'required', 'disabled'), true), 'Write inventory mode');
        $names = array();
        foreach ($tools as $t) {
            self::ensure(isset($t->name, $t->inputSchema->properties, $t->inputSchema->required, $t->annotations)
                && is_string($t->name) && !isset($names[$t->name]), 'Unique tool definition');
            $s = $t->inputSchema;
            self::ensure(isset($s->type) && $s->type === 'object' && $s->properties instanceof \stdClass
                && is_array($s->required) && isset($s->additionalProperties) && $s->additionalProperties === false,
                'Closed object input schema');
            foreach ($s->required as $key) {
                self::ensure(is_string($key) && property_exists($s->properties, $key), 'Required schema properties');
            }
            $read = in_array($t->name, array_merge(self::READS, array('mantis_get_attachment_policy')), true);
            $write = in_array($t->name, self::WRITES, true);
            self::ensure($read || $write, 'Known exact tool inventory');
            $destructive = in_array($t->name, array('mantis_update_issue', 'mantis_set_relationship', 'mantis_delete_relationship', 'mantis_delete_attachment'), true);
            self::ensure(isset($t->annotations->readOnlyHint, $t->annotations->idempotentHint, $t->annotations->destructiveHint)
                && $t->annotations->readOnlyHint === $read && $t->annotations->idempotentHint === $read
                && $t->annotations->destructiveHint === $destructive, 'Mutation annotations');
            if (in_array($t->name, array('mantis_get_workflow', 'mantis_get_relationships', 'mantis_get_attachment_policy'), true)) {
                self::ensure($s->required === array('issue_id') && isset($s->properties->issue_id)
                    && $s->properties->issue_id->type === 'integer' && $s->properties->issue_id->minimum === 1, 'Issue inspection input contract');
            }
            if ($t->name === 'mantis_upload_attachment') {
                self::ensure(isset($s->properties->note_id->type, $s->properties->note_id->minimum)
                    && $s->properties->note_id->type === 'integer' && $s->properties->note_id->minimum === 1
                    && !in_array('note_id', $s->required, true), 'Optional existing-note upload contract');
                foreach (array('issue_id', 'filename', 'mime_type', 'content_base64', 'sha256') as $key) {
                    self::ensure(in_array($key, $s->required, true), 'Required file upload contract');
                }
            }
            if ($t->name === 'mantis_add_note') {
                self::ensure(isset($s->properties->attachment->type, $s->properties->attachment->properties,
                    $s->properties->attachment->required, $s->properties->attachment->additionalProperties)
                    && $s->properties->attachment->type === 'object'
                    && $s->properties->attachment->additionalProperties === false, 'New-note attachment contract');
                foreach (array('filename', 'mime_type', 'content_base64', 'sha256') as $key) {
                    self::ensure(in_array($key, $s->properties->attachment->required, true)
                        && property_exists($s->properties->attachment->properties, $key), 'New-note file fields');
                }
            }
            $names[$t->name] = true;
        }
        self::ensure(!array_diff(self::READS, array_keys($names)), 'Required read tool inventory');
        $found = array_intersect(self::WRITES, array_keys($names));
        self::ensure(count($found) === 0 || count($found) === count(self::WRITES), 'Complete optional write inventory');
        self::ensure($writes !== 'required' || count($found) === count(self::WRITES), 'Expected write tools');
        self::ensure($writes !== 'disabled' || count($found) === 0, 'Expected read-only inventory');
        return array_keys($names);
    }

    public static function types($data) {
        self::ensure(isset($data->types) && is_array($data->types) && count($data->types) > 0, 'Relationship type list');
        $map = array();
        foreach ($data->types as $t) {
            self::ensure(isset($t->id, $t->label, $t->complementary_type_id) && property_exists($t, 'name')
                && is_int($t->id) && $t->id >= 0 && !isset($map[$t->id]) && is_string($t->label)
                && ($t->name === null || is_string($t->name)) && is_int($t->complementary_type_id), 'Relationship type definition');
            $map[$t->id] = $t;
        }
        foreach ($map as $t) {
            self::ensure(isset($map[$t->complementary_type_id])
                && $map[$t->complementary_type_id]->complementary_type_id === $t->id, 'Complementary relationship pair');
        }
        return $map;
    }

    public static function relationships($data, $id, array $types) {
        self::ensure(isset($data->issue_id, $data->relationships) && $data->issue_id === $id
            && is_array($data->relationships), 'Relationship issue context');
        $seen = array();
        foreach ($data->relationships as $r) {
            self::ensure(isset($r->id, $r->target_issue_id, $r->type->id) && is_int($r->id) && $r->id > 0
                && !isset($seen[$r->id]) && is_int($r->target_issue_id) && $r->target_issue_id > 0
                && $r->target_issue_id !== $id && is_int($r->type->id) && isset($types[$r->type->id]), 'Visible relationship definition');
            $seen[$r->id] = true;
        }
        return $data->relationships;
    }

    public static function workflow($w, $issue) {
        self::ensure(isset($w->issue_id, $w->project_id, $w->current_status_id, $w->statuses, $w->transitions,
            $w->workflow_and_status_access_next_ids, $w->is_readonly, $w->current_user_can_update_issue_fields)
            && $w->issue_id === $issue->id && $w->project_id === $issue->project->id
            && $w->current_status_id === $issue->status->id && is_bool($w->is_readonly)
            && is_bool($w->current_user_can_update_issue_fields) && is_array($w->statuses)
            && is_array($w->transitions) && is_array($w->workflow_and_status_access_next_ids), 'Workflow context');
        $statuses = array();
        foreach ($w->statuses as $s) {
            self::ensure(isset($s->id, $s->name, $s->label, $s->required_access_level, $s->current_user_has_status_access)
                && is_int($s->id) && $s->id > 0 && !isset($statuses[$s->id]) && is_string($s->name)
                && is_string($s->label) && is_int($s->required_access_level) && $s->required_access_level >= 0
                && is_bool($s->current_user_has_status_access), 'Workflow status metadata');
            $statuses[$s->id] = $s;
        }
        self::ensure(isset($statuses[$w->current_status_id]), 'Current workflow status');
        $graph = array();
        foreach ($w->transitions as $row) {
            self::ensure(isset($row->from_status_id, $row->to_status_ids) && isset($statuses[$row->from_status_id])
                && !isset($graph[$row->from_status_id]) && is_array($row->to_status_ids), 'Workflow transition row');
            $edges = array();
            foreach ($row->to_status_ids as $to) {
                self::ensure(is_int($to) && isset($statuses[$to]) && $to !== $row->from_status_id
                    && !isset($edges[$to]), 'Workflow transition target');
                $edges[$to] = true;
            }
            $graph[$row->from_status_id] = array_keys($edges);
        }
        self::ensure(count($graph) === count($statuses), 'Complete workflow graph');
        $expected = array_values(array_filter($graph[$w->current_status_id], function($id) use ($statuses) {
            return $statuses[$id]->current_user_has_status_access;
        }));
        $actual = $w->workflow_and_status_access_next_ids;
        sort($expected); sort($actual);
        self::ensure($expected === $actual, 'Workflow and status-access intersection');
    }

    public static function policy($data, $issue) {
        self::ensure(isset($data->issue_id, $data->project_id, $data->policy, $data->rules, $data->limits)
            && $data->issue_id === $issue->id && $data->project_id === $issue->project->id, 'Attachment policy context');
        $known = true;
        foreach (array('allowed_files', 'disallowed_files') as $key) {
            self::ensure(isset($data->policy->$key->available) && is_bool($data->policy->$key->available)
                && property_exists($data->policy->$key, 'value') && property_exists($data->policy->$key, 'extensions'), 'Policy availability');
            $p = $data->policy->$key;
            if ($p->available) {
                self::ensure(is_string($p->value) && is_array($p->extensions), 'Extension policy types');
                $expected = trim($p->value) === '' ? array() : array_values(array_unique(array_map('strtolower', explode(',', $p->value))));
                self::ensure($expected === $p->extensions, 'Native extension token representation');
            } else {
                $known = false;
                self::ensure($p->value === null && $p->extensions === null, 'Unavailable extension policy');
            }
        }
        $p = $data->policy;
        self::ensure(isset($p->max_file_size->available) && is_bool($p->max_file_size->available)
            && property_exists($p->max_file_size, 'value'), 'Native size availability');
        self::ensure($p->max_file_size->available
            ? (is_int($p->max_file_size->value) && $p->max_file_size->value >= 0) : $p->max_file_size->value === null, 'Native size policy');
        $r = $data->rules;
        self::ensure(isset($r->extension_policy_known, $r->extension_case_sensitive, $r->disallowed_takes_precedence)
            && property_exists($r, 'allow_other_extensions') && $r->extension_policy_known === $known
            && $r->extension_case_sensitive === false && $r->disallowed_takes_precedence === true
            && $r->allow_other_extensions === ($known ? trim($p->allowed_files->value) === '' : null), 'Native policy interpretation');
        $l = $data->limits;
        foreach (array('plugin_upload_bytes', 'mcp_request_bytes', 'php_upload_max_filesize_bytes', 'php_post_max_size_bytes') as $key) {
            self::ensure(isset($l->$key) && is_int($l->$key) && $l->$key >= 0, 'Upload/request size types');
        }
        $expected = $p->max_file_size->available ? min($p->max_file_size->value, $l->plugin_upload_bytes,
            $l->php_upload_max_filesize_bytes, $l->php_post_max_size_bytes) : null;
        self::ensure(property_exists($l, 'decoded_upload_upper_bound_bytes') && $l->decoded_upload_upper_bound_bytes === $expected
            && isset($l->request_envelope_and_other_limits_apply) && $l->request_envelope_and_other_limits_apply === true,
            'Effective upload upper bound and request caveat');
    }
}
