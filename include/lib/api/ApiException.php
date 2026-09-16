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
 * @brief Exception carrying a stable API error code, an English message and
 *        optional per-field validation errors.
 *
 * Thrown anywhere inside the Integration API and rendered as the JSON error
 * envelope by ApiResponse::sendError().
 */
class ApiException extends Exception {

    /** @var string One of the ApiErrorCodes constants */
    private $apiCode;

    /** @var array<int, array{field: string, code: string}> */
    private $fieldErrors;

    /** @var array<string, string> Extra response headers, e.g. Retry-After */
    private $headers = [];

    /**
     * @param string $apiCode     One of the ApiErrorCodes constants
     * @param string $message     Human-readable English message, never localised
     * @param array  $fieldErrors Optional list of ['field' => ..., 'code' => ...]
     */
    public function __construct($apiCode, $message = '', array $fieldErrors = []) {
        parent::__construct($message === '' ? $apiCode : $message, 0);
        $this->apiCode = $apiCode;
        $this->fieldErrors = $fieldErrors;
    }

    /**
     * @return string The stable error code
     */
    public function getApiCode() {
        return $this->apiCode;
    }

    /**
     * @return int HTTP status derived from the error code
     */
    public function getHttpStatus() {
        return ApiErrorCodes::httpStatus($this->apiCode);
    }

    /**
     * @return array<int, array{field: string, code: string}>
     */
    public function getFieldErrors() {
        return $this->fieldErrors;
    }

    /**
     * Attach response headers to send with the error.
     * @param array<string, string|int> $headers
     * @return ApiException
     */
    public function withHeaders(array $headers) {
        foreach ($headers as $name => $value) {
            $this->headers[$name] = (string) $value;
        }
        return $this;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders() {
        return $this->headers;
    }
}
