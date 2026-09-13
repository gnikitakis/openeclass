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
 * @file tests/api/run.php
 * @brief Smoke tests for the Integration API against a running platform.
 *
 * Plain PHP, no framework, in the style of tests/dspace_smoke.php.
 *
 * Environment:
 *   API_BASE_URL   e.g. http://localhost/api/integration/v1/index.php
 *   API_TOKEN      a token bound to a teacher (scopes: courses.read units.write announcements.write)
 *   API_PUBLISH_TOKEN (optional) a token bound to the same teacher with units.publish announcements.publish
 *   API_ADMIN_TOKEN   (optional) a token bound to a platform administrator
 *   API_COURSE     a course code the token user teaches
 *   API_OTHER_COURSE  (optional) a course code the user does not teach
 *   API_HOST_HEADER   (optional) Host header to send, e.g. "localhost"
 *
 * Writes are made in API_COURSE (units, resources, announcements) and are
 * left hidden; run this against a test installation only.
 *
 * Exit code 0 = all assertions passed, 1 = at least one failure.
 */

$base = rtrim(getenv('API_BASE_URL') ?: 'http://localhost/api/integration/v1/index.php', '/');
$token = getenv('API_TOKEN') ?: '';
$publishToken = getenv('API_PUBLISH_TOKEN') ?: '';
$course = getenv('API_COURSE') ?: '';
$otherCourse = getenv('API_OTHER_COURSE') ?: '';
$hostHeader = getenv('API_HOST_HEADER') ?: '';

$failures = 0;

function tassert(bool $cond, string $name, ?string $detail = null): void {
    global $failures;
    if ($cond) {
        echo "PASS  $name\n";
    } else {
        $failures++;
        echo "FAIL  $name" . ($detail !== null ? "  ($detail)" : '') . "\n";
    }
}

/**
 * @return array{status: int, headers: array<string,string>, body: array|null, raw: string}
 */
function call(string $method, string $path, ?string $token, array $extraHeaders = [], ?array $body = null): array {
    global $base, $hostHeader;
    $url = $base . '?_path=' . rawurlencode($path);
    $headers = ['Accept: application/json'];
    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    if ($hostHeader !== '') {
        $headers[] = 'Host: ' . $hostHeader;
    }
    foreach ($extraHeaders as $h) {
        $headers[] = $h;
    }
    $options = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 30,
    ];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        $options[CURLOPT_POSTFIELDS] = json_encode($body);
    }
    $options[CURLOPT_HTTPHEADER] = $headers;
    $ch = curl_init($url);
    curl_setopt_array($ch, $options);
    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $rawHeaders = substr((string) $raw, 0, $headerSize);
    $rawBody = substr((string) $raw, $headerSize);
    $parsed = [];
    foreach (explode("\n", $rawHeaders) as $line) {
        if (str_contains($line, ':')) {
            [$k, $v] = explode(':', $line, 2);
            $parsed[strtolower(trim($k))] = trim($v);
        }
    }
    return ['status' => $status, 'headers' => $parsed, 'body' => json_decode($rawBody, true), 'raw' => $rawBody];
}

echo "Integration API smoke tests against $base\n\n";

// 1. health, no token
$r = call('GET', '/health', null);
tassert($r['status'] === 200, 'GET /health returns 200', "got {$r['status']}");
tassert(str_starts_with($r['headers']['content-type'] ?? '', 'application/json'), 'health is JSON');
tassert(!empty($r['headers']['x-request-id']), 'X-Request-Id header present');
tassert(($r['body']['data']['schema_ok'] ?? false) === true, 'api_token schema is upgraded', $r['raw']);
tassert(($r['body']['data']['api_enabled'] ?? false) === true, 'API is enabled in platform config', $r['raw']);

// 2. unknown route
$r = call('GET', '/nope', null);
tassert($r['status'] === 404 and ($r['body']['error']['code'] ?? '') === 'not_found', 'unknown route is 404 not_found', $r['raw']);

// 3. auth failures
$r = call('GET', '/capabilities', null);
tassert($r['status'] === 401 and ($r['body']['error']['code'] ?? '') === 'unauthenticated', 'no token is 401 unauthenticated', $r['raw']);
$r = call('GET', '/capabilities', 'eclass_not_a_real_token');
tassert($r['status'] === 401 and ($r['body']['error']['code'] ?? '') === 'unauthenticated', 'bad token is 401 unauthenticated', $r['raw']);

if ($token === '') {
    echo "\nAPI_TOKEN not set; skipping authenticated tests\n";
    exit($failures ? 1 : 0);
}

// 4. capabilities
$r = call('GET', '/capabilities', $token);
tassert($r['status'] === 200, 'GET /capabilities returns 200', $r['raw']);
$acting = $r['body']['data']['acting'] ?? [];
tassert(($acting['is_admin'] ?? null) === false, 'acting is_admin is false');
tassert(($acting['is_power_user'] ?? null) === false, 'acting is_power_user is false');
tassert(($acting['user_id'] ?? 0) === ($r['body']['data']['user']['id'] ?? -1), 'acting uid equals bound user');
tassert(in_array('courses.read', $r['body']['data']['scopes'] ?? [], true), 'token has courses.read');

// 5. courses list
$r = call('GET', '/courses', $token);
tassert($r['status'] === 200 and is_array($r['body']['data'] ?? null), 'GET /courses returns a list', $r['raw']);
$codes = array_column($r['body']['data'] ?? [], 'code');
if ($course !== '') {
    tassert(in_array($course, $codes, true), "course $course is listed", implode(',', $codes));
}

// 6. course context through init.php
if ($course !== '') {
    $r = call('GET', "/courses/$course", $token);
    tassert($r['status'] === 200, "GET /courses/$course returns 200", $r['raw']);
    $acting = $r['body']['data']['acting'] ?? [];
    tassert(($r['body']['data']['code'] ?? '') === $course, 'course code echoed from init.php globals');
    tassert(($acting['is_editor'] ?? null) === true, 'init.php derived is_editor = true');
    tassert(($acting['is_admin'] ?? null) === false, 'init.php derived is_admin = false');
    tassert(is_string($acting['language'] ?? null) and $acting['language'] !== '', 'language loaded');
    tassert(is_int($r['body']['data']['counts']['units'] ?? null), 'course counts present');
}

// 7. unknown course and course not allowed
$r = call('GET', '/courses/NO_SUCH_COURSE_X', $token);
tassert($r['status'] === 404 and ($r['body']['error']['code'] ?? '') === 'not_found', 'unknown course is 404', $r['raw']);
if ($otherCourse !== '') {
    $r = call('GET', "/courses/$otherCourse", $token);
    tassert($r['status'] === 403 and in_array($r['body']['error']['code'] ?? '', ['not_editor', 'course_not_allowed'], true),
        "course $otherCourse without editorship is 403", $r['raw']);
}

// 8. method not allowed
$r = call('POST', '/courses', $token);
tassert($r['status'] === 405, 'POST /courses is 405', $r['raw']);

// 9. platform redirect is converted to JSON (host mismatch triggers init.php's tenant check)
$r = call('GET', "/courses/" . ($course !== '' ? $course : 'X'), $token, ['Host: not-the-platform.invalid']);
tassert($r['status'] !== 303 and $r['status'] !== 302, 'host mismatch never yields a bare redirect', "got {$r['status']}");
tassert(str_starts_with($r['headers']['content-type'] ?? '', 'application/json'), 'host mismatch answer is JSON', $r['raw']);

// 10. a token bound to a platform administrator never gains admin capability
$adminToken = getenv('API_ADMIN_TOKEN') ?: '';
if ($adminToken !== '') {
    $r = call('GET', '/capabilities', $adminToken);
    tassert($r['status'] === 200, 'admin-bound token: GET /capabilities returns 200', $r['raw']);
    $acting = $r['body']['data']['acting'] ?? [];
    tassert(($acting['is_admin'] ?? null) === false, 'admin-bound token: is_admin is false');
    tassert(($acting['is_power_user'] ?? null) === false, 'admin-bound token: is_power_user is false');
    if ($course !== '') {
        $r = call('GET', "/courses/$course", $adminToken);
        tassert($r['status'] === 403 and ($r['body']['error']['code'] ?? '') === 'not_editor',
            'admin-bound token: course without membership is 403 not_editor (no admin shortcut)', $r['raw']);
    }
}

// 11. units: draft-first, write versus publish, dry run
if ($course !== '') {
    $u = "/courses/$course/units";
    $r = call('POST', $u, $token, [], []);
    tassert($r['status'] === 422 and ($r['body']['error']['code'] ?? '') === 'validation_failed'
        and ($r['body']['error']['field_errors'][0]['field'] ?? '') === 'title',
        'unit without title is 422 with field error', $r['raw']);
    $before = count(call('GET', $u, $token)['body']['data'] ?? []);
    $r = call('POST', $u, $token, ['X-Dry-Run: true'], ['title' => 'Dry run unit']);
    tassert($r['status'] === 200 and ($r['body']['meta']['dry_run'] ?? false) === true
        and ($r['body']['data']['id'] ?? 0) > 0, 'dry run create answers with the would-be unit', $r['raw']);
    $after = count(call('GET', $u, $token)['body']['data'] ?? []);
    tassert($after === $before, 'dry run left the unit count unchanged', "$before -> $after");
    $r = call('POST', $u, $token, [], ['title' => 'Integration API unit', 'description' => '<p>Draft</p><script>alert(1)</script>',
        'start_date' => '2026-10-01']);
    tassert($r['status'] === 201 and ($r['body']['data']['visible'] ?? true) === false, 'created unit is hidden', $r['raw']);
    tassert(!str_contains($r['body']['data']['description'] ?? '', '<script'), 'unit description is purified', $r['raw']);
    tassert(($r['body']['data']['start_date'] ?? '') === '2026-10-01', 'unit start_date stored', $r['raw']);
    tassert(($r['body']['meta']['changes'][0]['table'] ?? '') === 'course_units', 'meta.changes lists the insert', $r['raw']);
    $unitId = $r['body']['data']['id'] ?? 0;
    $r = call('GET', $u, $token);
    tassert(in_array($unitId, array_column($r['body']['data'] ?? [], 'id'), true), 'new unit is listed');
    $r = call('GET', "$u/$unitId", $token);
    tassert($r['status'] === 200 and ($r['body']['data']['resources'] ?? null) === [], 'unit detail has empty resources', $r['raw']);
    $r = call('PATCH', "$u/$unitId", $token, [], ['title' => 'Integration API unit, edited']);
    tassert($r['status'] === 200 and ($r['body']['data']['title'] ?? '') === 'Integration API unit, edited',
        'hidden unit can be edited with units.write', $r['raw']);
    $r = call('PATCH', "$u/$unitId", $token, [], ['bogus' => 1]);
    tassert($r['status'] === 422, 'unknown field is 422', $r['raw']);
    $r = call('POST', "$u/$unitId/visibility", $token, [], ['visible' => true]);
    tassert($r['status'] === 403 and ($r['body']['error']['code'] ?? '') === 'scope_missing',
        'units.write cannot make a unit visible', $r['raw']);
    $r = call('GET', "$u/reorder", $token);
    tassert($r['status'] === 405, 'GET units/reorder is 405 (literal segment is not an id)', $r['raw']);
    $r = call('GET', "$u/999999999", $token);
    tassert($r['status'] === 404, 'unknown unit is 404', $r['raw']);

    // resources
    $r = call('POST', "$u/$unitId/resources", $token, [], ['type' => 'text', 'html' => '<p>Hello</p><script>x()</script>']);
    tassert($r['status'] === 201 and ($r['body']['data']['type'] ?? '') === 'text'
        and ($r['body']['data']['visible'] ?? true) === false, 'text resource created hidden', $r['raw']);
    tassert(!str_contains($r['body']['data']['comments'] ?? '', '<script'), 'text resource html is purified', $r['raw']);
    $textId = $r['body']['data']['id'] ?? 0;
    $r = call('POST', "$u/$unitId/resources", $token, [], ['type' => 'divider', 'title' => 'Part 2']);
    tassert($r['status'] === 201 and ($r['body']['data']['type'] ?? '') === 'divider', 'divider resource created', $r['raw']);
    $dividerId = $r['body']['data']['id'] ?? 0;
    $r = call('POST', "$u/$unitId/resources", $token, [], ['type' => 'document', 'document_id' => 999999999]);
    tassert($r['status'] === 404, 'document resource with unknown document is 404', $r['raw']);
    $r = call('POST', "$u/$unitId/resources", $token, [], ['type' => 'bogus']);
    tassert($r['status'] === 422, 'unknown resource type is 422', $r['raw']);
    $r = call('GET', "$u/$unitId/resources", $token);
    tassert(array_column($r['body']['data'] ?? [], 'id') === [$textId, $dividerId], 'resources listed in order', $r['raw']);
    $r = call('PATCH', "$u/$unitId/resources/$textId", $token, [], ['title' => 'Intro']);
    tassert($r['status'] === 200 and ($r['body']['data']['title'] ?? '') === 'Intro', 'hidden resource edited with units.write', $r['raw']);
    $r = call('POST', "$u/$unitId/resources/reorder", $token, [], ['ids' => [$dividerId, $textId]]);
    tassert($r['status'] === 403, 'reorder needs units.publish', $r['raw']);

    if ($publishToken !== '') {
        $r = call('POST', "$u/$unitId/resources/reorder", $publishToken, [], ['ids' => [$dividerId]]);
        tassert($r['status'] === 422, 'reorder with an incomplete id list is 422', $r['raw']);
        $r = call('POST', "$u/$unitId/resources/reorder", $publishToken, [], ['ids' => [$dividerId, $textId]]);
        tassert($r['status'] === 200 and array_column($r['body']['data'] ?? [], 'id') === [$dividerId, $textId],
            'resources reordered with units.publish', $r['raw']);
        $r = call('POST', "$u/$unitId/visibility", $publishToken, [], ['visible' => true]);
        tassert($r['status'] === 200 and ($r['body']['data']['visible'] ?? false) === true, 'unit made visible with units.publish', $r['raw']);
        $r = call('PATCH', "$u/$unitId", $token, [], ['title' => 'Should fail']);
        tassert($r['status'] === 403 and ($r['body']['error']['code'] ?? '') === 'scope_missing',
            'visible unit cannot be edited with units.write only', $r['raw']);
        $r = call('PATCH', "$u/$unitId", $publishToken, [], ['title' => 'Integration API unit, published']);
        tassert($r['status'] === 200, 'visible unit edited with units.publish', $r['raw']);
        $r = call('POST', "$u/$unitId/visibility", $publishToken, [], ['visible' => false]);
        tassert($r['status'] === 200 and ($r['body']['data']['visible'] ?? true) === false, 'unit hidden again', $r['raw']);
    }
}

// 12. announcements: draft-first, never email
if ($course !== '') {
    $a = "/courses/$course/announcements";
    $r = call('POST', $a, $token, [], ['title' => '', 'content' => 'x']);
    tassert($r['status'] === 422, 'announcement without title is 422', $r['raw']);
    $r = call('POST', $a, $token, [], ['title' => 'Hello', 'content' => '<p>Body</p>', 'start_display' => '2026-10-01 09:00',
        'stop_display' => 'not a date']);
    tassert($r['status'] === 422 and ($r['body']['error']['field_errors'][0]['field'] ?? '') === 'stop_display',
        'bad stop_display is a field error', $r['raw']);
    $r = call('POST', $a, $token, [], ['title' => 'Integration API announcement', 'content' => '<p>Body</p><script>x()</script>',
        'start_display' => '2026-10-01T09:00']);
    tassert($r['status'] === 201 and ($r['body']['data']['visible'] ?? true) === false, 'created announcement is hidden', $r['raw']);
    tassert(($r['body']['data']['start_display'] ?? '') === '2026-10-01 09:00:00', 'start_display normalised', $r['raw']);
    tassert(!str_contains($r['body']['data']['content'] ?? '', '<script'), 'announcement content is purified', $r['raw']);
    $annId = $r['body']['data']['id'] ?? 0;
    $r = call('GET', $a, $token);
    tassert(in_array($annId, array_column($r['body']['data'] ?? [], 'id'), true), 'new announcement is listed');
    $r = call('PATCH', "$a/$annId", $token, [], ['content' => '<p>Edited</p>']);
    tassert($r['status'] === 200 and ($r['body']['data']['content'] ?? '') === '<p>Edited</p>', 'hidden announcement edited', $r['raw']);
    $r = call('POST', "$a/$annId/visibility", $token, [], ['visible' => true]);
    tassert($r['status'] === 403 and ($r['body']['error']['code'] ?? '') === 'scope_missing',
        'announcements.write cannot publish', $r['raw']);
    if ($publishToken !== '') {
        $r = call('POST', "$a/$annId/visibility", $publishToken, [], ['visible' => true]);
        tassert($r['status'] === 200 and ($r['body']['data']['visible'] ?? false) === true, 'announcement published with announcements.publish', $r['raw']);
        $r = call('PATCH', "$a/$annId", $token, [], ['title' => 'Should fail']);
        tassert($r['status'] === 403, 'visible announcement cannot be edited with announcements.write only', $r['raw']);
        $r = call('POST', "$a/$annId/visibility", $publishToken, [], ['visible' => false]);
        tassert($r['status'] === 200 and ($r['body']['data']['visible'] ?? true) === false, 'announcement hidden again', $r['raw']);
    }
    $r = call('GET', "$a/999999999", $token);
    tassert($r['status'] === 404, 'unknown announcement is 404', $r['raw']);
}

echo "\n" . ($failures ? "$failures assertion(s) FAILED" : 'All assertions passed') . "\n";
exit($failures ? 1 : 0);
