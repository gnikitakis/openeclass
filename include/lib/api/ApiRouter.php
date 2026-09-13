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
 * @brief Route table of the Integration API.
 *
 * Every route declares, in one place, the scope it needs and whether it is
 * course-scoped. Scope and course checks happen here and in
 * ApiActingSession, never inside controllers, so "did we forget a check" is
 * answered by reading this table.
 */
class ApiRouter {

    /**
     * Route definitions: method, path pattern, required scope (null = no
     * token needed), controller class, method.
     * Patterns use {name} placeholders; {code} is always the course code.
     */
    const ROUTES = [
        ['GET', '/health', null, 'ApiHealthController', 'get'],
        ['GET', '/capabilities', 'any', 'ApiCapabilitiesController', 'get'],
        ['GET', '/courses', 'courses.read', 'ApiCourseController', 'index'],
        ['GET', '/courses/{code}', 'courses.read', 'ApiCourseController', 'show'],

        // Units. PATCH needs units.publish as well when the target is visible.
        ['GET', '/courses/{code}/units', 'units.read', 'ApiUnitController', 'index'],
        ['POST', '/courses/{code}/units', 'units.write', 'ApiUnitController', 'store'],
        ['POST', '/courses/{code}/units/reorder', 'units.publish', 'ApiUnitController', 'reorder'],
        ['GET', '/courses/{code}/units/{id}', 'units.read', 'ApiUnitController', 'show'],
        ['PATCH', '/courses/{code}/units/{id}', 'units.write', 'ApiUnitController', 'update'],
        ['POST', '/courses/{code}/units/{id}/visibility', 'units.publish', 'ApiUnitController', 'visibility'],
        ['GET', '/courses/{code}/units/{id}/resources', 'units.read', 'ApiUnitController', 'resources'],
        ['POST', '/courses/{code}/units/{id}/resources', 'units.write', 'ApiUnitController', 'addResource'],
        ['POST', '/courses/{code}/units/{id}/resources/reorder', 'units.publish', 'ApiUnitController', 'reorderResources'],
        ['PATCH', '/courses/{code}/units/{id}/resources/{rid}', 'units.write', 'ApiUnitController', 'updateResource'],
        ['POST', '/courses/{code}/units/{id}/resources/{rid}/visibility', 'units.publish', 'ApiUnitController', 'resourceVisibility'],

        // Announcements. PATCH needs announcements.publish as well when the target is visible.
        ['GET', '/courses/{code}/announcements', 'announcements.read', 'ApiAnnouncementController', 'index'],
        ['POST', '/courses/{code}/announcements', 'announcements.write', 'ApiAnnouncementController', 'store'],
        ['GET', '/courses/{code}/announcements/{id}', 'announcements.read', 'ApiAnnouncementController', 'show'],
        ['PATCH', '/courses/{code}/announcements/{id}', 'announcements.write', 'ApiAnnouncementController', 'update'],
        ['POST', '/courses/{code}/announcements/{id}/visibility', 'announcements.publish', 'ApiAnnouncementController', 'visibility'],
    ];

    /**
     * Find the route for a request.
     * @param ApiRequest $request
     * @return array{method: string, scope: string|null, controller: string, action: string, params: array, course: string|null}
     * @throws ApiException
     */
    public static function match(ApiRequest $request) {
        $pathMatched = false;
        foreach (self::ROUTES as [$method, $pattern, $scope, $controller, $action]) {
            // {code} is a course code; every other placeholder is a numeric id,
            // so literal segments such as "reorder" never match an id slot
            $regex = '#^' . preg_replace(['/\{code\}/', '/\{(\w+)\}/'], ['(?P<code>[^/]+)', '(?P<$1>\d+)'], $pattern) . '$#';
            if (!preg_match($regex, $request->path, $m)) {
                continue;
            }
            $pathMatched = true;
            if ($method !== $request->method) {
                continue;
            }
            $params = [];
            foreach ($m as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = $value;
                }
            }
            return [
                'method' => $method,
                'scope' => $scope,
                'controller' => $controller,
                'action' => $action,
                'params' => $params,
                'course' => $params['code'] ?? null,
            ];
        }
        if ($pathMatched) {
            throw new ApiException(ApiErrorCodes::METHOD_NOT_ALLOWED,
                "Method {$request->method} is not allowed on {$request->path}");
        }
        throw new ApiException(ApiErrorCodes::NOT_FOUND, "No route for {$request->method} {$request->path}");
    }

    /**
     * Enforce the route's scope on the token.
     * @param array            $route
     * @param ApiTokenIdentity $identity
     * @throws ApiException
     */
    public static function authorize(array $route, ApiTokenIdentity $identity) {
        if ($route['scope'] !== null and $route['scope'] !== 'any') {
            $identity->requireScope($route['scope']);
        }
    }

    /**
     * Invoke the controller. Controllers return ['data' => mixed, 'meta' => array, 'status' => int].
     * @param array           $route
     * @param ApiContext|null $context Null for routes that need no token
     * @param ApiRequest      $request
     * @return array
     */
    public static function dispatch(array $route, $context, ApiRequest $request) {
        $controller = $route['controller'];
        $action = $route['action'];
        if (!class_exists($controller) or !method_exists($controller, $action)) {
            throw new ApiException(ApiErrorCodes::INTERNAL, "Controller $controller::$action is not available");
        }
        $result = $controller::$action($request, $context, $route['params']);
        if (!is_array($result) or !array_key_exists('data', $result)) {
            throw new ApiException(ApiErrorCodes::INTERNAL, "Controller $controller::$action returned no data");
        }
        return $result;
    }
}
