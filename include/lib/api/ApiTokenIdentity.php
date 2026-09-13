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
 * @brief The identity behind an Integration API token.
 *
 * A token is a delegated, attenuated capability of one existing user: it
 * carries a bound user_id, a scope set, an optional course allowlist and an
 * optional department restriction. Tokens without a bound user (legacy
 * api/v1 tokens) are refused here and keep working on api/v1 unchanged.
 */
class ApiTokenIdentity {

    /** @var int api_token.id */
    public $tokenId;

    /** @var int Bound user id */
    public $userId;

    /** @var ApiScopes */
    public $scopes;

    /** @var bool Token may act in every course the user can edit */
    public $allCourses;

    /** @var string[] Course codes from api_token_course when not allCourses */
    public $courseCodes = [];

    /** @var int[]|null Department ids (with descendants) the token is restricted to */
    public $allowedDepartments = null;

    /** @var string Display prefix of the token */
    public $tokenPrefix;

    /** @var array<string, bool> Cached column presence */
    private static $columns = [];

    /**
     * Column names the Integration API needs on api_token.
     */
    const REQUIRED_COLUMNS = ['user_id', 'scopes', 'token_hash'];

    /**
     * True when the api_token table carries every column the API needs.
     * @return bool
     */
    public static function schemaReady() {
        foreach (self::REQUIRED_COLUMNS as $column) {
            if (!self::hasColumn($column)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Resolve the token presented in the request.
     * @param ApiRequest $request
     * @return ApiTokenIdentity
     * @throws ApiException
     */
    public static function fromRequest(ApiRequest $request) {
        if (!get_config('ext_apitoken_enabled')) {
            throw new ApiException(ApiErrorCodes::API_DISABLED, 'The Open eClass API is disabled');
        }
        if (!self::schemaReady()) {
            throw new ApiException(ApiErrorCodes::SCHEMA_MIGRATION_REQUIRED,
                'The api_token table has not been upgraded for the Integration API');
        }
        $token = $request->bearerToken();
        if ($token === null or $token === '') {
            throw new ApiException(ApiErrorCodes::UNAUTHENTICATED, 'A bearer token is required');
        }

        $row = Database::get()->querySingle('SELECT *, expired < NOW() AS token_expired
            FROM api_token WHERE token_hash = ?s', hash('sha256', $token));
        if (!$row) {
            throw new ApiException(ApiErrorCodes::UNAUTHENTICATED, 'The token is not valid');
        }
        if (!$row->enabled) {
            throw new ApiException(ApiErrorCodes::TOKEN_DISABLED, 'The token is disabled');
        }
        if ($row->token_expired) {
            throw new ApiException(ApiErrorCodes::TOKEN_EXPIRED, 'The token has expired');
        }
        if ($row->ip and !match_ip_to_ip_or_cidr(Log::get_client_ip(),
                explode(' ', canonicalize_whitespace($row->ip)))) {
            throw new ApiException(ApiErrorCodes::TOKEN_IP_DENIED, 'The token may not be used from this address');
        }
        if (!$row->user_id) {
            throw new ApiException(ApiErrorCodes::TOKEN_NOT_USER_BOUND,
                'The token is not bound to a user; bind it to a user in the API token administration');
        }

        $identity = new ApiTokenIdentity();
        $identity->tokenId = intval($row->id);
        $identity->userId = intval($row->user_id);
        $identity->tokenPrefix = $row->token_prefix ?? substr($token, 0, 16);
        $readOnly = self::hasColumn('read_only') ? (bool) $row->read_only : false;
        $identity->scopes = new ApiScopes($row->scopes ?? '', $readOnly);
        $identity->allCourses = self::hasColumn('all_courses') ? (bool) ($row->all_courses ?? true) : true;
        if (!$identity->allCourses) {
            $identity->courseCodes = array_map(function ($course) {
                return $course->code;
            }, Database::get()->queryArray('SELECT course.code FROM api_token_course
                JOIN course ON course.id = api_token_course.course_id
                WHERE token_id = ?d', $row->id));
        }
        if ($row->department_id !== null) {
            $identity->allowedDepartments = Access::getDepartmentDescendants($row->department_id);
        }
        if (self::hasColumn('last_used')) {
            Database::get()->query('UPDATE api_token SET last_used = NOW() WHERE id = ?d', $row->id);
        }
        return $identity;
    }

    /**
     * Whether the token's static allowlist admits a course. Live editorship
     * is checked separately by ApiActingSession.
     * @param string $courseCode
     * @param int    $courseId
     * @return bool
     */
    public function allowsCourse($courseCode, $courseId) {
        if (!$this->allCourses and !in_array($courseCode, $this->courseCodes, true)) {
            return false;
        }
        if ($this->allowedDepartments !== null
                and !Access::checkCourseDepartmentAccess($courseId, $this->allowedDepartments)) {
            return false;
        }
        return true;
    }

    /**
     * Require a scope or fail the request.
     * @param string $scope
     * @throws ApiException
     */
    public function requireScope($scope) {
        if (!$this->scopes->has($scope)) {
            throw new ApiException(ApiErrorCodes::SCOPE_MISSING, "The token lacks the scope '$scope'");
        }
    }

    /**
     * @param string $column
     * @return bool
     */
    private static function hasColumn($column) {
        if (!isset(self::$columns[$column])) {
            self::$columns[$column] = DBHelper::fieldExists('api_token', $column);
        }
        return self::$columns[$column];
    }
}
