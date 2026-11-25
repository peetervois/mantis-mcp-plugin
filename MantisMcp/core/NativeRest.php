<?php
/**
 * SPDX-License-Identifier: BSD-3-Clause
 * Copyright (c) 2026, Peeter Vois
 */

namespace Tauria\MantisMcp;

/** Compatibility for the unchanged MantisBT 2.26.4 file REST handler. */
final class NativeRest {
    public static function invoke(callable $handler, $request, $response, array $path, $fileRead = false) {
        if (!$fileRead || PHP_VERSION_ID < 80200 || !class_exists('IssueFileGetCommand')) {
            return call_user_func($handler, $request, $response, $path);
        }

        // 2.26.4 assigns this undeclared, unused property. Its REST deprecation
        // handler throws on PHP 8.2+, before it can return attachment content.
        $source = (new \ReflectionClass('IssueFileGetCommand'))->getFileName();
        $previous = null;
        $previous = set_error_handler(function($severity, $message, $file, $line) use (&$previous, $source) {
            if ($severity === E_DEPRECATED && $file === $source
                && $message === 'Creation of dynamic property IssueFileGetCommand::$user_id is deprecated') {
                return true;
            }
            if ($previous !== null) {
                return call_user_func($previous, $severity, $message, $file, $line);
            }
            return false;
        });
        try {
            return call_user_func($handler, $request, $response, $path);
        } finally {
            restore_error_handler();
        }
    }
}
