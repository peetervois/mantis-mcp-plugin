<?php
/**
 * SPDX-License-Identifier: BSD-3-Clause
 * Copyright (c) 2026, Peeter Vois
 */

namespace Tauria\MantisMcp;

final class Http {
    const OWNER_ATTRIBUTE = 'mantis_mcp_token_owner';

    private $lookupToken;
    private $currentUser;
    private $origin;
    private $enableWrites;
    private $maxBytes;

    public function __construct(callable $lookupToken, callable $currentUser, $mantisUrl,
        $enableWrites = false, $maxBytes = 1048576) {
        $this->lookupToken = $lookupToken;
        $this->currentUser = $currentUser;
        $this->origin = self::origin($mantisUrl);
        $this->enableWrites = (bool) $enableWrites;
        $this->maxBytes = self::requestLimit($maxBytes);
    }

    public static function requestLimit($configured) {
        return max(1024, min(10485760, (int) $configured));
    }

    /**
     * Added last to Slim's application middleware, so it runs before MantisBT's
     * AuthMiddleware. It affects only this plugin's endpoint.
     */
    public function guard($request, $response, callable $next) {
        if (!preg_match('~(?:^|/)plugins/MantisMcp/mcp/?$~D', $request->getUri()->getPath())) {
            return $next($request, $response);
        }

        $origin = $request->getHeaderLine('Origin');
        if ($request->hasHeader('Origin')
            && (count($request->getHeader('Origin')) !== 1
                || self::origin($origin, true) === null
                || self::origin($origin, true) !== $this->origin)) {
            return $this->failure($response, 403, 'Origin is not allowed.');
        }

        // Bind the request to its token owner; do not accept Mantis impersonation.
        if ($request->getHeaderLine('X-Mantis-Username') !== ''
            || $request->getHeaderLine('Mantis-Username') !== '') {
            return $this->failure($response, 403, 'MCP does not allow impersonation.');
        }

        $token = self::token($request->getHeaderLine('Authorization'));
        if ($token === null || count($request->getHeader('Authorization')) !== 1) {
            return $this->unauthorized($response);
        }
        try {
            $owner = call_user_func($this->lookupToken, $token);
            if ($owner === false || $owner === null || (int) $owner < 1) {
                return $this->unauthorized($response);
            }
            // MantisBT 2.26.4 expects a bare token, not a Bearer prefix.
            $request = $request->withHeader('Authorization', $token)
                ->withAttribute(self::OWNER_ATTRIBUTE, (int) $owner);
            return self::noStore($next($request, $response));
        } catch (\Throwable $error) {
            error_log('MantisMcp: authentication pipeline failed (' . get_class($error) . ').');
            return $this->failure($response, 500, 'MantisBT could not process this request.');
        }
    }

    public function handle($request, $response, callable $execute) {
        $owner = $request->getAttribute(self::OWNER_ATTRIBUTE);
        if (!$owner || (int) call_user_func($this->currentUser) !== (int) $owner) {
            return $this->unauthorized($response);
        }
        if ($request->getMethod() !== 'POST') {
            return $this->failure($response, 405, 'Use POST for stateless MCP.')
                ->withHeader('Allow', 'POST');
        }
        $contentType = strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0]));
        if ($contentType !== 'application/json') {
            return $this->failure($response, 415, 'Content-Type must be application/json.');
        }

        $body = $request->getBody();
        if ($body->getSize() !== null && $body->getSize() > $this->maxBytes) {
            return $this->failure($response, 413, 'MCP request body is too large.');
        }
        if ($body->isSeekable()) {
            $body->rewind();
        }
        $raw = $body->read($this->maxBytes + 1);
        if (strlen($raw) > $this->maxBytes) {
            return $this->failure($response, 413, 'MCP request body is too large.');
        }
        $message = json_decode($raw);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return self::noStore($response->withJson(
                Protocol::error(null, -32700, 'Parse error.'), 400));
        }

        $version = $request->getHeaderLine('MCP-Protocol-Version');
        $initializing = $message instanceof \stdClass
            && isset($message->method) && $message->method === 'initialize';
        if (!$initializing && $version !== ''
            && !in_array($version, Protocol::SUPPORTED_VERSIONS, true)) {
            return $this->failure($response, 400, 'Unsupported MCP-Protocol-Version.');
        }

        $protocol = new Protocol(new Tools($this->enableWrites), $execute);
        $reply = $protocol->handle($message);
        if ($reply === null) {
            return self::noStore($response->withStatus(202));
        }
        $status = isset($reply['error'])
            && in_array($reply['error']['code'], array(-32700, -32600), true) ? 400 : 200;
        return self::noStore($response->withJson($reply, $status));
    }

    public static function token($authorization) {
        if (!is_string($authorization) || strlen($authorization) > 1024) {
            return null;
        }
        if (preg_match('/\ABearer[ \t]+([^\s,]+)\z/i', $authorization, $match)) {
            return $match[1];
        }
        // Also support MantisBT's existing bare-token convention.
        return preg_match('/\A[^\s,]+\z/', $authorization) ? $authorization : null;
    }

    private function unauthorized($response) {
        return $this->failure($response, 401, 'A valid personal MantisBT API token is required.')
            ->withHeader('WWW-Authenticate', 'Bearer realm="MantisBT MCP"');
    }

    private function failure($response, $status, $message) {
        return self::noStore($response->withJson(array('error' => $message), $status));
    }

    private static function noStore($response) {
        return $response->withHeader('Cache-Control', 'no-store, private')
            ->withHeader('Vary', 'Authorization, Origin');
    }

    private static function origin($url, $strict = false) {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower($parts['scheme']), array('http', 'https'), true)
            || isset($parts['user']) || isset($parts['pass'])
            || ($strict && (isset($parts['path']) || isset($parts['query']) || isset($parts['fragment'])))) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
        return $scheme . '://' . strtolower($parts['host']) . ':' . $port;
    }
}
