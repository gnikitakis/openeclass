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
 * @brief GET /openapi.yaml: the description of this API, served by the
 *        platform that implements it.
 *
 * A client reads this to learn which operations exist and what they
 * accept, instead of being told out of band. It needs no token, like
 * /health: the document describes an interface, it exposes no data.
 */
class ApiSpecController {

    /** Location of the document, outside the web-reachable directory */
    const PATH = __DIR__ . '/../openapi.yaml';

    /**
     * @param ApiRequest      $request
     * @param ApiContext|null $context Always null: no token is required
     * @param array           $params
     * @return array Never returns; the document ends the request
     * @throws ApiException
     */
    public static function get(ApiRequest $request, $context, array $params) {
        if (!is_file(self::PATH)) {
            throw new ApiException(ApiErrorCodes::NOT_FOUND, 'The description document is not installed');
        }
        ApiResponse::sendText(file_get_contents(self::PATH), 'application/yaml; charset=utf-8');
        return ['data' => null];
    }
}
