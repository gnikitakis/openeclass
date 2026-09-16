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
 * @file api/integration/v1/index.php
 * @brief Front controller of the Integration API, the only web-reachable
 *        file of the API.
 *
 * Request lifecycle:
 *  1. minimal platform bootstrap (config, database) so the token can be
 *     resolved without a session;
 *  2. route lookup and token/scope checks (ApiRouter, ApiTokenIdentity),
 *     then the rate limit and, for writes with an Idempotency-Key, the
 *     replay or reservation of that key;
 *  3. ApiActingSession::prepare() validates user, course and editorship and
 *     synthesises the session;
 *  4. include/init.php runs exactly as it does for every platform page;
 *  5. ApiActingSession::verify() asserts what init.php derived;
 *  6. the controller runs and ApiResponse emits JSON.
 *
 * init.php assigns its globals at file scope, so it must be required here,
 * at file scope, never from inside a function.
 */

$webDir = dirname(dirname(dirname(__DIR__)));
chdir($webDir);

require_once 'vendor/autoload.php';
require_once 'include/main_lib.php';
require_once 'include/lib/api/ApiBootstrap.php';
ApiBootstrap::registerAutoloader();
register_shutdown_function(['ApiResponse', 'shutdownGuard']);

try {
    if (!file_exists('config/config.php')) {
        throw new ApiException(ApiErrorCodes::MAINTENANCE, 'The platform is not installed');
    }
    include_once 'config/config.php';
    require_once 'modules/admin/debug.php';
    require_once 'modules/db/database.php';
    require_once 'modules/db/dbhelper.php';
    require_once 'api/v1/access.class.php';
    try {
        Database::get();
    } catch (Exception $e) {
        throw new ApiException(ApiErrorCodes::MAINTENANCE, 'The platform database is not reachable');
    }

    $apiRequest = ApiRequest::fromGlobals();
    $apiRoute = ApiRouter::match($apiRequest);

    if ($apiRoute['scope'] === null) {
        // Routes without a token never run init.php
        $apiResult = ApiRouter::dispatch($apiRoute, null, $apiRequest);
        ApiResponse::send($apiResult['data'], $apiResult['meta'] ?? [], $apiResult['status'] ?? 200);
    }

    $apiIdentity = ApiTokenIdentity::fromRequest($apiRequest);
    ApiRouter::authorize($apiRoute, $apiIdentity);
    ApiRateLimiter::check($apiRequest, $apiIdentity);
    ApiIdempotency::begin($apiRequest, $apiIdentity);
    $apiInit = ApiActingSession::prepare($apiIdentity, $apiRoute['course']);
} catch (ApiException $e) {
    ApiResponse::sendError($e);
} catch (Throwable $t) {
    error_log('Integration API bootstrap failure: ' . $t->getMessage() . ' in ' . $t->getFile() . ':' . $t->getLine());
    ApiResponse::sendError(new ApiException(ApiErrorCodes::INTERNAL, 'Internal error'));
}

// ---- platform bootstrap, at file scope, with the synthesised session ----
define('MAINTENANCE_PAGE', true);
define('SKIP_DOUBLE_LOGIN_LOCK', true);
$require_login = true;
$require_current_course = $apiInit['require_current_course'];
$require_editor = $apiInit['require_editor'];
require_once 'include/init.php';
header('Content-Type: application/json; charset=utf-8');

try {
    ApiActingSession::verify($apiIdentity, $apiRoute['course']);
    $apiContext = ApiContext::fromGlobals($apiRequest, $apiIdentity);
    $apiResult = ApiRouter::dispatch($apiRoute, $apiContext, $apiRequest);
    ApiResponse::send($apiResult['data'], $apiResult['meta'] ?? [], $apiResult['status'] ?? 200);
} catch (ApiException $e) {
    ApiResponse::sendError($e);
} catch (Throwable $t) {
    error_log('Integration API failure: ' . $t->getMessage() . ' in ' . $t->getFile() . ':' . $t->getLine());
    ApiResponse::sendError(new ApiException(ApiErrorCodes::INTERNAL, 'Internal error'));
}
