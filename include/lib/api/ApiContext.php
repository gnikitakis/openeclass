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
 * @brief What a controller may know about the request: the token identity,
 *        the acting user and, for course routes, the course as init.php
 *        loaded it.
 *
 * Values come from the globals init.php set; controllers never read
 * globals themselves.
 */
class ApiContext {

    /** @var ApiRequest */
    public $request;

    /** @var ApiTokenIdentity */
    public $identity;

    /** @var object User row (id, username, givenname, surname, email, status, lang) */
    public $user;

    /** @var int|null course.id when the route is course-scoped */
    public $courseId;

    /** @var string|null course.code */
    public $courseCode;

    /** @var string|null Course title */
    public $courseTitle;

    /** @var string|null Interface language in effect */
    public $language;

    /** @var bool */
    public $isEditor;

    /** @var bool */
    public $isCourseAdmin;

    /**
     * Build the context from the globals init.php produced.
     * @param ApiRequest       $request
     * @param ApiTokenIdentity $identity
     * @return ApiContext
     */
    public static function fromGlobals(ApiRequest $request, ApiTokenIdentity $identity) {
        $g = $GLOBALS;
        $c = new ApiContext();
        $c->request = $request;
        $c->identity = $identity;
        $c->user = ApiActingSession::user();
        $c->courseId = isset($g['course_id']) ? intval($g['course_id']) : null;
        $c->courseCode = $g['course_code'] ?? null;
        $c->courseTitle = $g['currentCourseName'] ?? null;
        $c->language = $g['language'] ?? null;
        $c->isEditor = !empty($g['is_editor']);
        $c->isCourseAdmin = !empty($g['is_course_admin']);
        return $c;
    }

    /**
     * Diagnostic view of the acting identity as the platform derived it.
     * Exposes only what the token holder already knows about themselves.
     * @return array
     */
    public function actingSummary() {
        $g = $GLOBALS;
        return [
            'user_id' => intval($g['uid'] ?? 0),
            'username' => $this->user->username ?? null,
            'is_admin' => (bool) ($g['is_admin'] ?? false),
            'is_power_user' => (bool) ($g['is_power_user'] ?? false),
            'is_editor' => $this->isEditor,
            'is_course_admin' => $this->isCourseAdmin,
            'course_status' => isset($g['status']) ? intval($g['status']) : null,
            'language' => $this->language,
        ];
    }
}
