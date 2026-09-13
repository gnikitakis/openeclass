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
 *   API_TOKEN      a token bound to a teacher (scopes: courses.read)
 *   API_COURSE     a course code the token user teaches
 *   API_OTHER_COURSE  (optional) a course code the user does not teach
 *   API_HOST_HEADER   (optional) Host header to send, e.g. "localhost"
 *
 * Exit code 0 = all assertions passed, 1 = at least one failure.
 */

$base = rtrim(getenv('API_BASE_URL') ?: 'http://localhost/api/integration/v1/index.php', '/');
$token = getenv('API_TOKEN') ?: '';
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
function call(string $method, string $path, ?string $token, array $extraHeaders = []): array {
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
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 30,
    ]);
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

echo "\n" . ($failures ? "$failures assertion(s) FAILED" : 'All assertions passed') . "\n";
exit($failures ? 1 : 0);
