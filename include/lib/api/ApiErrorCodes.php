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
 * @brief Stable, machine-readable error codes of the Integration API.
 *
 * The codes form a closed set: clients branch on them, so a code is never
 * renamed or renumbered once published. Each code maps to exactly one HTTP
 * status.
 */
class ApiErrorCodes {

    const BAD_REQUEST = 'bad_request';
    const UNAUTHENTICATED = 'unauthenticated';
    const TOKEN_EXPIRED = 'token_expired';
    const TOKEN_DISABLED = 'token_disabled';
    const TOKEN_IP_DENIED = 'token_ip_denied';
    const TOKEN_NOT_USER_BOUND = 'token_not_user_bound';
    const SCOPE_MISSING = 'scope_missing';
    const COURSE_NOT_ALLOWED = 'course_not_allowed';
    const NOT_EDITOR = 'not_editor';
    const NOT_FOUND = 'not_found';
    const METHOD_NOT_ALLOWED = 'method_not_allowed';
    const TYPE_CHANGE_DESTRUCTIVE = 'type_change_destructive';
    const IDEMPOTENCY_CONFLICT = 'idempotency_conflict';
    const QUOTA_EXCEEDED = 'quota_exceeded';
    const FILE_TYPE_NOT_ALLOWED = 'file_type_not_allowed';
    const MIME_MISMATCH = 'mime_mismatch';
    const VALIDATION_FAILED = 'validation_failed';
    const UNSUPPORTED_QUESTION_TYPE = 'unsupported_question_type';
    const EXERCISE_INVALID = 'exercise_invalid';
    const RATE_LIMITED = 'rate_limited';
    const INTERNAL = 'internal';
    const PLATFORM_REDIRECTED = 'platform_redirected';
    const MAINTENANCE = 'maintenance';
    const API_DISABLED = 'api_disabled';
    const SCHEMA_MIGRATION_REQUIRED = 'schema_migration_required';

    /**
     * HTTP status for each error code.
     * @var array<string, int>
     */
    private static $httpStatus = [
        self::BAD_REQUEST => 400,
        self::UNAUTHENTICATED => 401,
        self::TOKEN_EXPIRED => 401,
        self::TOKEN_DISABLED => 401,
        self::TOKEN_IP_DENIED => 403,
        self::TOKEN_NOT_USER_BOUND => 403,
        self::SCOPE_MISSING => 403,
        self::COURSE_NOT_ALLOWED => 403,
        self::NOT_EDITOR => 403,
        self::NOT_FOUND => 404,
        self::METHOD_NOT_ALLOWED => 405,
        self::TYPE_CHANGE_DESTRUCTIVE => 409,
        self::IDEMPOTENCY_CONFLICT => 409,
        self::QUOTA_EXCEEDED => 413,
        self::FILE_TYPE_NOT_ALLOWED => 415,
        self::MIME_MISMATCH => 415,
        self::VALIDATION_FAILED => 422,
        self::UNSUPPORTED_QUESTION_TYPE => 422,
        self::EXERCISE_INVALID => 422,
        self::RATE_LIMITED => 429,
        self::INTERNAL => 500,
        self::PLATFORM_REDIRECTED => 502,
        self::MAINTENANCE => 503,
        self::API_DISABLED => 503,
        self::SCHEMA_MIGRATION_REQUIRED => 503,
    ];

    /**
     * @param string $code One of the class constants
     * @return int HTTP status code (500 for an unknown code)
     */
    public static function httpStatus($code) {
        return self::$httpStatus[$code] ?? 500;
    }

    /**
     * @return array<string, int> All codes with their HTTP status, for the capabilities document
     */
    public static function all() {
        return self::$httpStatus;
    }
}
