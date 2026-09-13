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
 * @file tests/api/seed.php
 * @brief Prepare an installed platform for tests/api/run.php.
 *
 * Run from the platform root, on the command line, on a TEST installation:
 *   php tests/api/seed.php
 *
 * It applies the Integration API schema, enables the API, and creates (if
 * missing) a teacher "apiteacher", a course APITEST1 the teacher edits, a
 * course APITEST2 the teacher is not a member of, and three tokens bound to
 * users: one with write scopes, one with publish scopes, and one bound to a
 * platform administrator. The tokens are printed once and are regenerated on
 * every run.
 *
 * The units, announcements and documents of the two test courses are removed
 * on every run, so run.php always starts from a known state and can assert on
 * exact listings. No other course is touched.
 */

if (php_sapi_name() !== 'cli') {
    die("Run from the command line.\n");
}

$webDir = dirname(dirname(__DIR__));
chdir($webDir);
require_once 'vendor/autoload.php';
require_once 'include/main_lib.php';
if (!file_exists('config/config.php')) {
    die("Platform is not installed (config/config.php missing).\n");
}
include_once 'config/config.php';
require_once 'modules/admin/debug.php';
require_once 'modules/db/database.php';
require_once 'modules/db/dbhelper.php';
require_once 'include/lib/course.class.php';
require_once 'include/lib/hierarchy.class.php';
require_once 'include/lib/user.class.php';
require_once 'include/course_settings.php';
require_once 'modules/create_course/functions.php';
require_once 'include/lib/api/ApiBootstrap.php';
ApiBootstrap::registerAutoloader();

$urlServer = get_config('base_url');
$urlAppend = preg_replace('|^https?://[^/]+/|', '/', $urlServer);
$language = get_config('default_language') ?: 'en';
$tbl_options = 'DEFAULT CHARACTER SET=utf8mb4 COLLATE utf8mb4_unicode_520_ci ENGINE=InnoDB';

// create_modules() reads the $modules table that include/init.php defines for
// web requests; provide the same module ids here (init.php, non-collaborative list)
$modules = array_fill_keys([
    MODULE_ID_AGENDA, MODULE_ID_LINKS, MODULE_ID_DOCS, MODULE_ID_VIDEO, MODULE_ID_ASSIGN,
    MODULE_ID_ANNOUNCE, MODULE_ID_FORUM, MODULE_ID_EXERCISE, MODULE_ID_GROUPS, MODULE_ID_MESSAGE,
    MODULE_ID_GLOSSARY, MODULE_ID_EBOOK, MODULE_ID_CHAT, MODULE_ID_QUESTIONNAIRE, MODULE_ID_LP,
    MODULE_ID_WIKI, MODULE_ID_BLOG, MODULE_ID_WALL, MODULE_ID_GRADEBOOK, MODULE_ID_ATTENDANCE,
    MODULE_ID_TC, MODULE_ID_PROGRESS, MODULE_ID_REQUEST, MODULE_ID_STICKY_NOTES, MODULE_ID_H5P,
], []);

$db = Database::get();

echo "Applying Integration API schema... ";
api_token_schema_upgrade($tbl_options);
echo "done\n";

set_config('ext_apitoken_enabled', 1);
echo "API enabled (ext_apitoken_enabled = 1)\n";

$department = $db->querySingle('SELECT id FROM hierarchy ORDER BY id LIMIT 1');
if (!$department) {
    die("No department in hierarchy table.\n");
}
$departmentId = $department->id;

// Teacher
$teacher = $db->querySingle("SELECT id FROM user WHERE username = 'apiteacher'");
if (!$teacher) {
    $r = $db->query("INSERT INTO user
        SET surname = ?s, givenname = ?s, username = ?s, password = ?s,
            email = ?s, status = ?d, registered_at = " . DBHelper::timeAfter() . ",
            expires_at = DATE_ADD(NOW(), INTERVAL 10 YEAR),
            lang = ?s, am = '', email_public = 0, phone_public = 0, am_public = 0, pic_public = 0,
            description = '', verified_mail = " . EMAIL_VERIFIED . ", whitelist = ''",
        'Teacher', 'Api', 'apiteacher', password_hash(bin2hex(random_bytes(12)), PASSWORD_DEFAULT),
        'apiteacher@example.invalid', USER_TEACHER, $language);
    $teacherId = $r->lastInsertID;
    $db->query('INSERT IGNORE INTO personal_calendar_settings (user_id) VALUES (?d)', $teacherId);
    $db->query('INSERT INTO user_department (user, department) VALUES (?d, ?d)', $teacherId, $departmentId);
    echo "Created user apiteacher (id $teacherId)\n";
} else {
    $teacherId = $teacher->id;
    echo "User apiteacher exists (id $teacherId)\n";
}

/**
 * @param string $code
 * @param string $title
 * @param int|null $teacherMemberId user to register as teacher, or null
 * @return int course id
 */
function seed_course($code, $title, $teacherMemberId) {
    global $db, $departmentId;
    $existing = $db->querySingle('SELECT id FROM course WHERE code = ?s', $code);
    if ($existing) {
        echo "Course $code exists (id {$existing->id})\n";
        seed_course_extras($existing->id, $code, $teacherMemberId);
        return $existing->id;
    }
    if (!is_dir("courses/$code") and !create_course_dirs($code)) {
        die("Could not create course directories for $code\n");
    }
    $r = $db->query("INSERT INTO course SET
            code = ?s, lang = ?s, title = ?s, visible = ?d, course_license = 0,
            prof_names = ?s, public_code = ?s, doc_quota = ?f, video_quota = ?f,
            group_quota = ?f, dropbox_quota = ?f, password = '', flipped_flag = 0,
            view_type = 'units', start_date = " . DBHelper::timeAfter() . ", keywords = '',
            created = " . DBHelper::timeAfter() . ", glossary_expand = 0, glossary_index = 1,
            description = ''",
        $code, 'en', $title, COURSE_OPEN, 'Api Teacher', $code,
        100 * 1024 * 1024, 100 * 1024 * 1024, 100 * 1024 * 1024, 100 * 1024 * 1024);
    if (!$r) {
        die("Course insert failed for $code\n");
    }
    $courseId = $r->lastInsertID;
    echo "Created course $code (id $courseId)\n";
    seed_course_extras($courseId, $code, $teacherMemberId);
    return $courseId;
}

/**
 * Ensure the course has its module entries and, if requested, the teacher membership.
 * @param int      $courseId
 * @param string   $code
 * @param int|null $teacherMemberId
 */
function seed_course_extras($courseId, $code, $teacherMemberId) {
    global $db, $departmentId;
    $dept = $db->querySingle('SELECT COUNT(*) AS n FROM course_department WHERE course = ?d', $courseId);
    if (!$dept or !$dept->n) {
        $db->query('INSERT IGNORE INTO course_department (course, department) VALUES (?d, ?d)',
            $courseId, $departmentId);
        echo "  department linked for $code\n";
    }
    $modules = $db->querySingle('SELECT COUNT(*) AS n FROM course_module WHERE course_id = ?d', $courseId);
    if (!$modules or !$modules->n) {
        create_modules($courseId);
        echo "  modules created for $code\n";
    }
    if ($teacherMemberId) {
        $db->query("INSERT IGNORE INTO course_user
            SET course_id = ?d, user_id = ?d, status = ?d, editor = 1,
                reg_date = NOW(), receive_mail = 1, document_timestamp = NOW()",
            $courseId, $teacherMemberId, USER_TEACHER);
        echo "  apiteacher is teacher of $code\n";
    }
}

$courseIds = [
    seed_course('APITEST1', 'Integration API test course', $teacherId),
    seed_course('APITEST2', 'Integration API course without membership', null),
];

// Start every run from a known state: the smoke tests assert on exact
// listings, and the API has no delete endpoints to undo a previous run.
// Only the two seeded test courses are touched.
foreach ($courseIds as $index => $courseId) {
    $code = $index === 0 ? 'APITEST1' : 'APITEST2';
    reset_course_content($courseId, $code);
}

/**
 * Remove the units, announcements and main-area documents of a seeded test
 * course, together with the files those documents point at.
 * @param int    $courseId
 * @param string $code
 */
function reset_course_content($courseId, $code) {
    global $db, $webDir;
    $db->query('DELETE FROM unit_resources WHERE unit_id IN
        (SELECT id FROM course_units WHERE course_id = ?d)', $courseId);
    $db->query('DELETE FROM course_units WHERE course_id = ?d', $courseId);
    $db->query('DELETE FROM announcement WHERE course_id = ?d', $courseId);
    $documents = $db->queryArray('SELECT path, format FROM document
        WHERE course_id = ?d AND subsystem = ?d', $courseId, MAIN);
    $basedir = "$webDir/courses/$code/document";
    foreach ($documents as $document) {
        $path = $basedir . $document->path;
        if ($document->format !== '.dir' and is_file($path)) {
            unlink($path);
        }
    }
    foreach (array_reverse($documents) as $document) {
        $path = $basedir . $document->path;
        if ($document->format === '.dir' and is_dir($path)) {
            @rmdir($path);
        }
    }
    $db->query('DELETE FROM document WHERE course_id = ?d AND subsystem = ?d', $courseId, MAIN);
    echo "  content of $code reset\n";
}

// Token bound to the teacher (regenerated every run)
$token = 'eclass_' . bin2hex(random_bytes(32));
$db->query("DELETE FROM api_token WHERE name = 'integration-api-smoke-test'");
$db->query("INSERT INTO api_token
    SET token = ?s, token_hash = ?s, token_prefix = ?s, name = ?s, comments = ?s,
        department_id = ?d, ip = '', enabled = 1, created = NOW(), updated = NOW(),
        expired = DATE_ADD(NOW(), INTERVAL 1 YEAR), user_id = ?d, scopes = ?s",
    $token, hash('sha256', $token), substr($token, 0, 16), 'integration-api-smoke-test',
    'Created by tests/api/seed.php', $departmentId, $teacherId,
    'courses.read units.write announcements.write documents.write');

// Token bound to the teacher with publish scopes (regenerated every run)
$publishToken = 'eclass_' . bin2hex(random_bytes(32));
$db->query("DELETE FROM api_token WHERE name = 'integration-api-publish-test'");
$db->query("INSERT INTO api_token
    SET token = ?s, token_hash = ?s, token_prefix = ?s, name = ?s, comments = ?s,
        department_id = ?d, ip = '', enabled = 1, created = NOW(), updated = NOW(),
        expired = DATE_ADD(NOW(), INTERVAL 1 YEAR), user_id = ?d, scopes = ?s",
    $publishToken, hash('sha256', $publishToken), substr($publishToken, 0, 16), 'integration-api-publish-test',
    'Created by tests/api/seed.php', $departmentId, $teacherId,
    'courses.read units.publish announcements.publish documents.publish');

// Token bound to a platform administrator: must never gain admin capability
$admin = $db->querySingle('SELECT user_id FROM admin ORDER BY user_id LIMIT 1');
$adminToken = '';
if ($admin) {
    $adminToken = 'eclass_' . bin2hex(random_bytes(32));
    $db->query("DELETE FROM api_token WHERE name = 'integration-api-admin-test'");
    $db->query("INSERT INTO api_token
        SET token = ?s, token_hash = ?s, token_prefix = ?s, name = ?s, comments = ?s,
            department_id = ?d, ip = '', enabled = 1, created = NOW(), updated = NOW(),
            expired = DATE_ADD(NOW(), INTERVAL 1 YEAR), user_id = ?d, scopes = ?s",
        $adminToken, hash('sha256', $adminToken), substr($adminToken, 0, 16), 'integration-api-admin-test',
        'Created by tests/api/seed.php', $departmentId, $admin->user_id, 'courses.read');
}

echo "\nAPI_TOKEN=$token\nAPI_PUBLISH_TOKEN=$publishToken\nAPI_ADMIN_TOKEN=$adminToken\n"
    . "API_COURSE=APITEST1\nAPI_OTHER_COURSE=APITEST2\n";
