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
 * @brief The security boundary of the Integration API.
 *
 * The API does not re-implement the platform's course and permission logic.
 * It synthesises a cookie-less, request-lifetime PHP session holding exactly
 * the keys a logged-in user would have (uid, status, dbname, names,
 * language) and then lets include/init.php run as it does for every page.
 * init.php derives $uid, $is_editor, $is_course_admin, the course globals
 * and the language from that session.
 *
 * Two properties make this a structural guarantee rather than a promise:
 *  - no admin key is ever placed in the session, so init.php sets
 *    $is_admin, $is_power_user, $is_usermanage_user and
 *    $is_departmentmanage_user to false (init.php, "else" branch of the
 *    admin flag block), whatever the bound user is on the platform;
 *  - every check init.php performs (course exists, visibility, editorship,
 *    prerequisites) is re-evaluated live on every request; nothing is
 *    cached at token issue time.
 *
 * Because init.php may end a request with redirect_to_home_page() (which
 * calls exit), prepare() validates everything that could trigger such a
 * redirect before init.php runs, and ApiResponse::shutdownGuard() turns any
 * redirect that still happens into a JSON error.
 */
class ApiActingSession {

    /** @var object|null user row of the acting user */
    private static $user = null;

    /** @var object|null course row of the current request */
    private static $course = null;

    /**
     * Validate the acting user and course and synthesise the session.
     * Must be called before include/init.php is required.
     *
     * @param ApiTokenIdentity $identity
     * @param string|null      $courseCode Course code from the route, or null for
     *                                     platform-level routes
     * @return array{require_current_course: bool, require_editor: bool}
     * @throws ApiException
     */
    public static function prepare(ApiTokenIdentity $identity, $courseCode) {
        if (get_config('maintenance') == 1) {
            throw new ApiException(ApiErrorCodes::MAINTENANCE, 'The platform is in maintenance mode');
        }
        if (get_config('upgrade_begin')) {
            throw new ApiException(ApiErrorCodes::MAINTENANCE, 'A platform upgrade is in progress');
        }

        $user = Database::get()->querySingle('SELECT id, username, givenname, surname, email, status, lang
            FROM user WHERE id = ?d', $identity->userId);
        if (!$user) {
            throw new ApiException(ApiErrorCodes::TOKEN_NOT_USER_BOUND, 'The user bound to the token no longer exists');
        }
        self::$user = $user;

        $course = null;
        if ($courseCode !== null) {
            $course = Database::get()->querySingle('SELECT id, code, title, visible, lang
                FROM course WHERE code = ?s', $courseCode);
            if (!$course) {
                throw new ApiException(ApiErrorCodes::NOT_FOUND, "Course '$courseCode' does not exist");
            }
            if (!$identity->allowsCourse($course->code, $course->id)) {
                throw new ApiException(ApiErrorCodes::COURSE_NOT_ALLOWED, "The token may not act in course '$courseCode'");
            }
            $membership = Database::get()->querySingle('SELECT status, editor FROM course_user
                WHERE user_id = ?d AND course_id = ?d', $user->id, $course->id);
            if (!$membership or !($membership->status == USER_TEACHER or $membership->editor)) {
                throw new ApiException(ApiErrorCodes::NOT_EDITOR,
                    "The user bound to the token is not a teacher or editor of course '$courseCode'");
            }
            self::$course = $course;
        }

        self::startSession();
        $_SESSION['uid'] = intval($user->id);
        $_SESSION['status'] = intval($user->status);
        $_SESSION['givenname'] = $user->givenname;
        $_SESSION['surname'] = $user->surname;
        $_SESSION['email'] = $user->email;
        $_SESSION['login_timestamp'] = date('Y-m-d H:i:s');
        if ($user->lang) {
            $_SESSION['langswitch'] = $user->lang;
        }
        // Deliberately never set: is_admin, is_power_user, is_usermanage_user, is_departmentmanage_user
        if ($course) {
            $_SESSION['dbname'] = $course->code;
        }

        return [
            'require_current_course' => $course !== null,
            'require_editor' => $course !== null,
        ];
    }

    /**
     * Assert, after include/init.php has run, that the platform derived the
     * identity we intended. Any mismatch is a hard failure: it means the
     * boundary does not hold and nothing may be served.
     *
     * @param ApiTokenIdentity $identity
     * @param string|null      $courseCode
     * @throws ApiException
     */
    public static function verify(ApiTokenIdentity $identity, $courseCode) {
        $g = $GLOBALS;
        $problems = [];
        if (($g['is_admin'] ?? null) !== false) {
            $problems[] = 'is_admin is not false';
        }
        if (($g['is_power_user'] ?? null) !== false) {
            $problems[] = 'is_power_user is not false';
        }
        if (intval($g['uid'] ?? 0) !== $identity->userId) {
            $problems[] = 'uid does not match the token user';
        }
        if (!empty($g['toolContent_ErrorExists'])) {
            // init.php refused the acting user for this page (not editor, course
            // inactive/expired, session lost); report it as a permission failure
            throw new ApiException(ApiErrorCodes::NOT_EDITOR,
                'The platform refused the acting user for this course: ' . strip_tags($g['toolContent_ErrorExists']));
        }
        if ($courseCode !== null) {
            if (($g['course_code'] ?? null) !== $courseCode) {
                $problems[] = 'course_code does not match the requested course';
            }
            if (empty($g['course_id'])) {
                $problems[] = 'course_id is not set';
            }
            if (empty($g['is_editor'])) {
                $problems[] = 'is_editor is not true';
            }
        }
        if ($problems) {
            throw new ApiException(ApiErrorCodes::INTERNAL,
                'Acting session verification failed: ' . implode('; ', $problems));
        }
    }

    /**
     * @return object|null The acting user row (after prepare())
     */
    public static function user() {
        return self::$user;
    }

    /**
     * @return object|null The current course row (after prepare())
     */
    public static function course() {
        return self::$course;
    }

    /**
     * Start a cookie-less session that lives only for this request.
     * init.php only calls session_start() when no session is active.
     */
    private static function startSession() {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        ini_set('session.use_cookies', '0');
        ini_set('session.use_only_cookies', '0');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cache_limiter', '');
        session_id(bin2hex(random_bytes(16)));
        session_start();
        $_SESSION = [];
        register_shutdown_function(function () {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_abort();
            }
        });
    }
}
