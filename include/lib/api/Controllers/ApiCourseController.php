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
 * @brief Courses the token may act in.
 */
class ApiCourseController {

    /**
     * GET /courses
     * @param ApiRequest $request
     * @param ApiContext $context
     * @param array      $params
     * @return array
     */
    public static function index(ApiRequest $request, ApiContext $context, array $params) {
        return ['data' => self::allowedCourses($context)];
    }

    /**
     * GET /courses/{code}: the course as init.php loaded it for the acting user.
     * @param ApiRequest $request
     * @param ApiContext $context
     * @param array      $params
     * @return array
     */
    public static function show(ApiRequest $request, ApiContext $context, array $params) {
        global $visible, $currentCourseLanguage, $course_prof_names;
        $counts = Database::get()->querySingle('SELECT
            (SELECT COUNT(*) FROM course_units WHERE course_id = ?d) AS units,
            (SELECT COUNT(*) FROM document WHERE course_id = ?d AND subsystem = 0) AS documents,
            (SELECT COUNT(*) FROM announcement WHERE course_id = ?d) AS announcements,
            (SELECT COUNT(*) FROM exercise WHERE course_id = ?d) AS exercises',
            $context->courseId, $context->courseId, $context->courseId, $context->courseId);
        $data = [
            'id' => $context->courseId,
            'code' => $context->courseCode,
            'title' => $context->courseTitle,
            'lang' => $currentCourseLanguage ?? null,
            'visible' => isset($visible) ? intval($visible) : null,
            'prof_names' => $course_prof_names ?? null,
            'counts' => [
                'units' => intval($counts->units ?? 0),
                'documents' => intval($counts->documents ?? 0),
                'announcements' => intval($counts->announcements ?? 0),
                'exercises' => intval($counts->exercises ?? 0),
            ],
            'effective_scopes' => $context->identity->scopes->all(),
            'acting' => $context->actingSummary(),
        ];
        return ['data' => $data];
    }

    /**
     * Courses the token may act in right now: the token's allowlist
     * intersected with live teacher/editor membership of the bound user.
     * @param ApiContext $context
     * @return array
     */
    public static function allowedCourses(ApiContext $context) {
        $rows = Database::get()->queryArray('SELECT course.id, course.code, course.title, course.visible, course.lang
            FROM course JOIN course_user ON course_user.course_id = course.id
            WHERE course_user.user_id = ?d AND (course_user.status = ?d OR course_user.editor = 1)
            ORDER BY course.title', $context->user->id, USER_TEACHER);
        $list = [];
        foreach ($rows as $row) {
            if (!$context->identity->allowsCourse($row->code, $row->id)) {
                continue;
            }
            $list[] = [
                'id' => intval($row->id),
                'code' => $row->code,
                'title' => $row->title,
                'lang' => $row->lang,
                'visible' => intval($row->visible),
                'effective_scopes' => $context->identity->scopes->all(),
            ];
        }
        return $list;
    }
}
