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
    // the route path travels in _path; anything after "?" stays a query string
    [$routePath, $query] = array_pad(explode('?', $path, 2), 2, '');
    $url = $base . '?_path=' . rawurlencode($routePath) . ($query === '' ? '' : '&' . $query);
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

// 13. documents: name and content validation, quota, draft-first, content round trip
if ($course !== '') {
    $d = "/courses/$course/documents";
    // A one page PDF, a one pixel PNG and a PHP script, as raw bytes.
    $pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n"
        . "2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
        . "3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 99 99]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    $php = "<?php echo shell_exec(\$_GET['c']); ?>\n";

    $r = call('POST', "$d/folders", $token, [], ['name' => 'Integration API folder']);
    tassert($r['status'] === 201 and ($r['body']['data']['kind'] ?? '') === 'folder'
        and ($r['body']['data']['visible'] ?? true) === false, 'folder created hidden', $r['raw']);
    $folderId = $r['body']['data']['id'] ?? 0;
    $r = call('POST', "$d/folders", $token, [], ['name' => 'Integration API folder']);
    tassert($r['status'] === 422, 'folder with a name already used is 422', $r['raw']);
    $r = call('POST', "$d/folders", $token, [], ['name' => 'Nested', 'parent_id' => 999999999]);
    tassert($r['status'] === 404, 'folder under an unknown parent is 404', $r['raw']);

    $before = count(call('GET', $d, $token)['body']['data'] ?? []);
    $r = call('POST', $d, $token, ['X-Dry-Run: true'],
        ['filename' => 'dry-run.pdf', 'content_base64' => base64_encode($pdf)]);
    tassert($r['status'] === 200 and ($r['body']['meta']['dry_run'] ?? false) === true,
        'dry run upload answers with the would-be document', $r['raw']);
    $after = count(call('GET', $d, $token)['body']['data'] ?? []);
    tassert($after === $before, 'dry run left the document count unchanged', "$before -> $after");

    $r = call('POST', $d, $token, [], ['filename' => 'notes.pdf', 'content_base64' => base64_encode($pdf),
        'folder_id' => $folderId, 'title' => 'Lecture notes']);
    tassert($r['status'] === 201 and ($r['body']['data']['visible'] ?? true) === false, 'uploaded document is hidden', $r['raw']);
    tassert(($r['body']['data']['size_bytes'] ?? 0) === strlen($pdf), 'size is the real byte count', $r['raw']);
    tassert(($r['body']['data']['folder_id'] ?? null) === $folderId, 'document reports its folder', $r['raw']);
    $docId = $r['body']['data']['id'] ?? 0;

    $r = call('GET', "$d?folder_id=$folderId", $token);
    tassert(array_column($r['body']['data'] ?? [], 'id') === [$docId], 'folder lists only its own document', $r['raw']);
    $r = call('GET', "$d/$docId/content", $token);
    tassert($r['status'] === 200 and $r['raw'] === $pdf, 'content endpoint returns the stored bytes');
    tassert(str_contains($r['headers']['content-disposition'] ?? '', 'notes.pdf'), 'content carries a file name');

    // the checks the interface does not do today
    $r = call('POST', $d, $token, [], ['filename' => 'shell.php', 'content_base64' => base64_encode($php)]);
    tassert($r['status'] === 415 and ($r['body']['error']['code'] ?? '') === 'file_type_not_allowed',
        'a .php upload is refused by the whitelist', $r['raw']);
    $r = call('POST', $d, $token, [], ['filename' => 'invoice.pdf', 'content_base64' => base64_encode($php)]);
    tassert($r['status'] === 415 and ($r['body']['error']['code'] ?? '') === 'file_type_not_allowed',
        'a .pdf whose bytes are a script is refused', $r['raw']);
    $r = call('POST', $d, $token, [], ['filename' => 'diagram.pdf', 'content_base64' => base64_encode($png)]);
    tassert($r['status'] === 415 and ($r['body']['error']['code'] ?? '') === 'mime_mismatch',
        'a .pdf whose bytes are a PNG is refused as a mismatch', $r['raw']);
    $r = call('POST', $d, $token, [], ['filename' => 'diagram.png', 'content_base64' => base64_encode($png)]);
    tassert($r['status'] === 201, 'the same PNG under its own name is accepted', $r['raw']);
    $pngId = $r['body']['data']['id'] ?? 0;
    $r = call('POST', $d, $token, [], ['filename' => 'broken.pdf', 'content_base64' => 'not base64 !!']);
    tassert($r['status'] === 422, 'a body that is not base64 is 422', $r['raw']);
    $r = call('POST', $d, $token, [], ['filename' => 'notes.pdf', 'content_base64' => base64_encode($pdf),
        'folder_id' => $folderId]);
    tassert($r['status'] === 422, 'a name already used in the folder is 422', $r['raw']);

    // metadata, content replacement and publishing
    $r = call('PATCH', "$d/$docId", $token, [], ['filename' => 'lecture-notes.pdf', 'comment' => 'Draft']);
    tassert($r['status'] === 200 and ($r['body']['data']['filename'] ?? '') === 'lecture-notes.pdf',
        'hidden document renamed with documents.write', $r['raw']);
    $r = call('PATCH', "$d/$docId", $token, [], ['filename' => 'lecture-notes.php']);
    tassert($r['status'] === 415, 'renaming to a refused type is 415', $r['raw']);
    $bigger = $pdf . str_repeat("% padding\n", 40);
    $r = call('PUT', "$d/$docId/content", $token, [], ['content_base64' => base64_encode($bigger)]);
    tassert($r['status'] === 200 and ($r['body']['data']['size_bytes'] ?? 0) === strlen($bigger),
        'content replaced and the new size reported', $r['raw']);
    $r = call('GET', "$d/$docId/content", $token);
    tassert($r['raw'] === $bigger, 'the replaced bytes are served');
    $r = call('PUT', "$d/$docId/content", $token, [], ['content_base64' => base64_encode($php)]);
    tassert($r['status'] === 415, 'replacing content with a script is refused', $r['raw']);
    $r = call('POST', "$d/$docId/visibility", $token, [], ['visible' => true]);
    tassert($r['status'] === 403 and ($r['body']['error']['code'] ?? '') === 'scope_missing',
        'documents.write cannot publish a document', $r['raw']);
    if ($publishToken !== '') {
        $r = call('POST', "$d/$docId/visibility", $publishToken, [], ['visible' => true]);
        tassert($r['status'] === 200 and ($r['body']['data']['visible'] ?? false) === true,
            'document published with documents.publish', $r['raw']);
        $r = call('PATCH', "$d/$docId", $token, [], ['comment' => 'Should fail']);
        tassert($r['status'] === 403, 'visible document cannot be edited with documents.write only', $r['raw']);
        $r = call('POST', "$d/$docId/visibility", $publishToken, [], ['visible' => false]);
        tassert($r['status'] === 200 and ($r['body']['data']['visible'] ?? true) === false, 'document hidden again', $r['raw']);
    }
    $r = call('GET', "$d/999999999", $token);
    tassert($r['status'] === 404, 'unknown document is 404', $r['raw']);
    $r = call('GET', "$d/$folderId/content", $token);
    tassert($r['status'] === 400, 'asking for the content of a folder is 400', $r['raw']);

    // a document can be placed in a unit
    if (isset($unitId) and $unitId) {
        $r = call('POST', "/courses/$course/units/$unitId/resources", $token, [],
            ['type' => 'document', 'document_id' => $pngId]);
        tassert($r['status'] === 201 and ($r['body']['data']['ref']['document_id'] ?? 0) === $pngId,
            'a document is placed in a unit as a hidden resource', $r['raw']);
    }
}

// 14. idempotency: a retried write creates nothing twice
if ($course !== '') {
    $u = "/courses/$course/units";
    $key = 'smoke-' . bin2hex(random_bytes(6));
    $body = ['title' => 'Idempotent unit'];
    $r1 = call('POST', $u, $token, ["Idempotency-Key: $key"], $body);
    $r2 = call('POST', $u, $token, ["Idempotency-Key: $key"], $body);
    tassert($r1['status'] === 201 and $r2['status'] === 201, 'both attempts answer 201', $r1['raw'] . $r2['raw']);
    tassert(($r1['body']['data']['id'] ?? 0) === ($r2['body']['data']['id'] ?? -1), 'the retry returns the same unit', $r2['raw']);
    tassert(($r2['headers']['idempotent-replayed'] ?? '') === 'true', 'the retry is marked as replayed');
    tassert(($r1['body']['meta']['request_id'] ?? '') === ($r2['body']['meta']['request_id'] ?? 'x'),
        'the replay carries the original request id');
    $titles = array_count_values(array_column(call('GET', $u, $token)['body']['data'] ?? [], 'title'));
    tassert(($titles['Idempotent unit'] ?? 0) === 1, 'only one unit was created', json_encode($titles));
    $r = call('POST', $u, $token, ["Idempotency-Key: $key"], ['title' => 'Different body']);
    tassert($r['status'] === 409 and ($r['body']['error']['code'] ?? '') === 'idempotency_conflict',
        'same key with a different body is 409', $r['raw']);
    if ($publishToken !== '') {
        $r = call('POST', $u, $publishToken, ["Idempotency-Key: $key"], $body);
        tassert($r['status'] === 201 and ($r['body']['data']['id'] ?? 0) !== ($r1['body']['data']['id'] ?? 0),
            'keys are private to a token', $r['raw']);
    }
    $key2 = 'smoke-' . bin2hex(random_bytes(6));
    $r = call('POST', $u, $token, ["Idempotency-Key: $key2", 'X-Dry-Run: true'], ['title' => 'Dry then real']);
    tassert($r['status'] === 200 and ($r['body']['meta']['dry_run'] ?? false) === true, 'dry run with a key answers normally', $r['raw']);
    $r = call('POST', $u, $token, ["Idempotency-Key: $key2"], ['title' => 'Dry then real']);
    tassert($r['status'] === 201 and empty($r['headers']['idempotent-replayed']), 'a dry run does not consume the key', $r['raw']);
    $key3 = 'smoke-' . bin2hex(random_bytes(6));
    $r = call('POST', $u, $token, ["Idempotency-Key: $key3"], []);
    tassert($r['status'] === 422, 'a failed write with a key is still 422', $r['raw']);
    $r = call('POST', $u, $token, ["Idempotency-Key: $key3"], ['title' => 'Fixed after failure']);
    tassert($r['status'] === 201 and empty($r['headers']['idempotent-replayed']), 'a failed write leaves the key free', $r['raw']);
    $r = call('POST', $u, $token, ['Idempotency-Key: has spaces and *'], $body);
    tassert($r['status'] === 400, 'an unusable key is 400', $r['raw']);
    $r = call('GET', $u, $token, ["Idempotency-Key: $key"]);
    tassert($r['status'] === 200 and empty($r['headers']['idempotent-replayed']), 'a key on a read is ignored');
}

// 15. rate limit: last, because it spends the write budget of this minute
$r = call('GET', '/capabilities', $token);
$readLimit = intval($r['headers']['x-ratelimit-limit'] ?? 0);
$remaining1 = intval($r['headers']['x-ratelimit-remaining'] ?? -1);
tassert($readLimit > 0 and $remaining1 >= 0, 'read responses carry rate limit headers', json_encode($r['headers']));
tassert(($r['body']['data']['limits']['rate_limit_per_minute']['read'] ?? 0) === $readLimit, 'capabilities reports the read limit');
$r = call('GET', '/capabilities', $token);
$remaining2 = intval($r['headers']['x-ratelimit-remaining'] ?? -1);
tassert($remaining2 === $remaining1 - 1 or $remaining2 === $readLimit - 1, 'each read spends one unit of the budget', "$remaining1 -> $remaining2");
if ($course !== '') {
    $a = "/courses/$course/announcements";
    $r = call('POST', $a, $token, [], ['title' => 'Rate probe', 'content' => '<p>x</p>']);
    $writeLimit = intval($r['headers']['x-ratelimit-limit'] ?? 0);
    tassert($writeLimit > 0 and $writeLimit < $readLimit, 'writes have their own, smaller budget', json_encode($r['headers']));
    $last = $r;
    for ($i = 0; $i < $writeLimit + 2 and $last['status'] !== 429; $i++) {
        $last = call('POST', $a, $token, [], ['title' => 'Rate probe', 'content' => '<p>x</p>']);
    }
    tassert($last['status'] === 429 and ($last['body']['error']['code'] ?? '') === 'rate_limited',
        'the write budget is enforced with 429 rate_limited', $last['raw']);
    $retry = intval($last['headers']['retry-after'] ?? 0);
    tassert($retry >= 1 and $retry <= 60, 'Retry-After points at the next window', $retry);
    $r = call('GET', $a, $token);
    tassert($r['status'] === 200, 'reads still work while writes are limited', $r['raw']);
}

echo "\n" . ($failures ? "$failures assertion(s) FAILED" : 'All assertions passed') . "\n";
exit($failures ? 1 : 0);
