<?php
/**
 * SPDX-License-Identifier: BSD-3-Clause
 * Copyright (c) 2026, Peeter Vois
 */

require_once __DIR__ . '/core/Protocol.php';
require_once __DIR__ . '/core/AttachmentDownload.php';
require_once __DIR__ . '/core/AttachmentUpload.php';
require_once __DIR__ . '/core/Tools.php';
require_once __DIR__ . '/core/Http.php';
require_once __DIR__ . '/core/RestApi.php';

/**
 * Install this directory as MantisBT/plugins/MantisMcp.
 */
class MantisMcpPlugin extends MantisPlugin {
    public function register() {
        $this->name = 'Mantis MCP';
        $this->description = 'MCP tools authenticated with each caller\'s personal MantisBT API token.';
        $this->version = \Tauria\MantisMcp\Protocol::VERSION;
        $this->requires = array('MantisCore' => '2.26.4');
        $this->author = 'Peeter Vois';
        $this->url = 'https://github.com/peetervois/mantis-mcp-plugin';
    }

    public function config() {
        return array(
            'enable_write_tools' => OFF,
            'max_request_bytes' => 1048576,
            'max_download_bytes' => \Tauria\MantisMcp\AttachmentDownload::DEFAULT_MAX_BYTES,
            'max_upload_bytes' => \Tauria\MantisMcp\AttachmentUpload::DEFAULT_MAX_BYTES,
        );
    }

    public function hooks() {
        return array('EVENT_REST_API_ROUTES' => 'routes');
    }

    public function routes($event, $arguments) {
        require_api('api_token_api.php');

        $app = $arguments['app'];
        $http = new \Tauria\MantisMcp\Http(
            function($token) { return api_token_get_user($token); },
            function() { return auth_get_current_user_id(); },
            config_get_global('path'),
            (bool) plugin_config_get('enable_write_tools', OFF, true),
            plugin_config_get('max_request_bytes', 1048576, true)
        );

        // App middleware is LIFO. Register before MantisBT's AuthMiddleware runs,
        // to normalize Bearer headers only for this endpoint and disallow guest/cookie auth.
        $app->add(array($http, 'guard'));
        $maxDownload = plugin_config_get('max_download_bytes',
            \Tauria\MantisMcp\AttachmentDownload::DEFAULT_MAX_BYTES, true);
        $maxUpload = plugin_config_get('max_upload_bytes',
            \Tauria\MantisMcp\AttachmentUpload::DEFAULT_MAX_BYTES, true);
        $endpoint = function($request, $response, $args) use ($http, $maxDownload, $maxUpload) {
            $api = new \Tauria\MantisMcp\RestApi($request, null, array(), $maxDownload, $maxUpload);
            return $http->handle($request, $response, array($api, 'execute'));
        };
        $app->group(plugin_route_group(), function() use ($app, $endpoint) {
            $app->map(array('POST', 'GET', 'DELETE', 'PUT', 'PATCH', 'OPTIONS'), '/mcp', $endpoint);
            $app->map(array('POST', 'GET', 'DELETE', 'PUT', 'PATCH', 'OPTIONS'), '/mcp/', $endpoint);
        });
    }
}
