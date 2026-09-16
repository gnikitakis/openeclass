<?php
/*
 *  ========================================================================
 *  * Open eClass
 *  * E-learning and Course Management System
 *  * ========================================================================
 *  * Copyright 2003-2026, Greek Universities Network - GUnet
 *  *
 *  * Open eClass is an open platform distributed in the hope that it will
 *  * be useful (without any warranty), under the terms of the GNU (General
 *  * Public License) as published by the Free Software Foundation.
 *  * The full license can be read in "/info/license/license_gpl.txt".
 *  *
 *  * Contact address: GUnet Asynchronous eLearning Group
 *  *                  e-mail: info@openeclass.org
 *  * ========================================================================
 *
 */

/**
 * @brief JSON response envelope of the Integration API.
 *
 * Success:  { "data": ..., "meta": { "request_id": ..., "dry_run": false } }
 * Failure:  { "error": { "code": ..., "message": ..., "field_errors": [...] },
 *             "meta": { "request_id": ... } }
 *
 * Every response carries an X-Request-Id header. Emitting a response ends
 * the request.
 */
class ApiResponse {

    /** @var string|null */
    private static $requestId = null;

    /** @var bool Set once a JSON body has been emitted */
    private static $sent = false;

    /** @var callable|null Called with (array $body, int $status) before a success envelope is sent */
    private static $recorder = null;

    /**
     * Register a callback that sees every success envelope before it is
     * sent (used to store idempotent responses).
     * @param callable $recorder function (array $body, int $status): void
     */
    public static function setRecorder(callable $recorder) {
        self::$recorder = $recorder;
    }

    /**
     * Request id for correlation: the client's X-Request-Id if it is a
     * plausible identifier, otherwise a generated one.
     * @return string
     */
    public static function requestId() {
        if (self::$requestId === null) {
            $client = $_SERVER['HTTP_X_REQUEST_ID'] ?? '';
            if (preg_match('/^[A-Za-z0-9._-]{8,64}$/', $client)) {
                self::$requestId = $client;
            } else {
                self::$requestId = bin2hex(random_bytes(8));
            }
        }
        return self::$requestId;
    }

    /**
     * Emit a success envelope and end the request.
     * @param mixed $data       JSON-serialisable payload
     * @param array $meta       Extra meta fields (merged over request_id / dry_run)
     * @param int   $httpStatus HTTP status, default 200
     */
    public static function send($data, array $meta = [], $httpStatus = 200) {
        $body = [
            'data' => $data,
            'meta' => array_merge(['request_id' => self::requestId(), 'dry_run' => false], $meta),
        ];
        if (self::$recorder !== null) {
            try {
                call_user_func(self::$recorder, $body, $httpStatus);
            } catch (Throwable $t) {
                error_log('Integration API response recorder failed: ' . $t->getMessage());
            }
        }
        self::emit($body, $httpStatus);
    }

    /**
     * Emit a previously stored JSON envelope unchanged and end the request.
     * @param string $json       The stored body
     * @param int    $httpStatus The stored status
     * @param array  $headers    Extra headers, name => value
     */
    public static function sendRaw($json, $httpStatus, array $headers = []) {
        self::emit($json, $httpStatus, true, $headers);
    }

    /**
     * Emit an error envelope for an ApiException and end the request.
     * @param ApiException $e
     */
    public static function sendError(ApiException $e) {
        $error = [
            'code' => $e->getApiCode(),
            'message' => $e->getMessage(),
        ];
        if ($e->getFieldErrors()) {
            $error['field_errors'] = $e->getFieldErrors();
        }
        self::emit(['error' => $error, 'meta' => ['request_id' => self::requestId()]], $e->getHttpStatus(),
            true, $e->getHeaders());
    }

    /**
     * Emit a stored file as the response body and end the request. Used by
     * the one endpoint that returns bytes instead of JSON, so that an agent
     * can read material that is already in the course.
     *
     * @param string $path     Readable path of the file
     * @param string $filename Name to offer the client
     */
    public static function sendFile($path, $filename) {
        self::$sent = true;
        if (!headers_sent()) {
            $protocol = $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1';
            header("$protocol 200 OK", true, 200);
            header('Content-Type: application/octet-stream');
            header('Content-Length: ' . filesize($path));
            header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename)
                . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store');
            header('X-Request-Id: ' . self::requestId());
        }
        readfile($path);
        exit;
    }

    /**
     * Shutdown guard: if the platform bootstrap ended the request with a
     * redirect (redirect_to_home_page() calls exit), replace the redirect
     * with a JSON error so an API client never receives a bare 303.
     */
    public static function shutdownGuard() {
        if (self::$sent or headers_sent()) {
            return;
        }
        $location = null;
        foreach (headers_list() as $header) {
            if (stripos($header, 'Location:') === 0) {
                $location = trim(substr($header, 9));
                break;
            }
        }
        if ($location === null) {
            return;
        }
        header_remove('Location');
        self::emit([
            'error' => [
                'code' => ApiErrorCodes::PLATFORM_REDIRECTED,
                'message' => 'The platform bootstrap redirected instead of serving the request',
                'redirect' => $location,
            ],
            'meta' => ['request_id' => self::requestId()],
        ], ApiErrorCodes::httpStatus(ApiErrorCodes::PLATFORM_REDIRECTED), false);
    }

    /**
     * @param array|string $body       Envelope to encode, or an already encoded JSON string
     * @param int          $httpStatus
     * @param bool         $exit       Whether to end the request after emitting
     * @param array        $headers    Extra headers, name => value
     */
    private static function emit($body, $httpStatus, $exit = true, array $headers = []) {
        self::$sent = true;
        if (!headers_sent()) {
            foreach ($headers as $name => $value) {
                header($name . ': ' . $value);
            }
            // A status line set with header("HTTP/1.1 303 ...") (as
            // redirect_to_home_page() does) takes precedence over
            // http_response_code() under PHP-FPM, so the status is set the
            // same way to replace it.
            $protocol = $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1';
            header("$protocol $httpStatus " . self::reasonPhrase($httpStatus), true, $httpStatus);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store');
            header('X-Request-Id: ' . self::requestId());
        }
        echo is_string($body) ? rtrim($body) : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
        if ($exit) {
            exit;
        }
    }

    /**
     * @param int $status
     * @return string Standard reason phrase for the statuses the API uses
     */
    private static function reasonPhrase($status) {
        $phrases = [
            200 => 'OK', 201 => 'Created', 204 => 'No Content',
            400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden', 404 => 'Not Found',
            405 => 'Method Not Allowed', 409 => 'Conflict', 413 => 'Content Too Large',
            415 => 'Unsupported Media Type', 422 => 'Unprocessable Content', 429 => 'Too Many Requests',
            500 => 'Internal Server Error', 502 => 'Bad Gateway', 503 => 'Service Unavailable',
        ];
        return $phrases[$status] ?? 'Unknown';
    }
}
