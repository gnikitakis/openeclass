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
            $regex = '#^' . preg_replace('/\{(\w+)\}/', '(?P<$1>[^/]+)', $pattern) . '$#';
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
