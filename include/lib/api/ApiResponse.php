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
        self::emit($body, $httpStatus);
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
        self::emit(['error' => $error, 'meta' => ['request_id' => self::requestId()]], $e->getHttpStatus());
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
     * @param array $body
     * @param int   $httpStatus
     * @param bool  $exit Whether to end the request after emitting
     */
    private static function emit(array $body, $httpStatus, $exit = true) {
        self::$sent = true;
        if (!headers_sent()) {
            http_response_code($httpStatus);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store');
            header('X-Request-Id: ' . self::requestId());
        }
        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
        if ($exit) {
            exit;
        }
    }
}
