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
 * @brief GET /health: liveness and readiness without authentication.
 */
class ApiHealthController {

    /**
     * @param ApiRequest      $request
     * @param ApiContext|null $context Always null: no token is required
     * @param array           $params
     * @return array
     */
    public static function get(ApiRequest $request, $context, array $params) {
        $maintenance = get_config('maintenance') == 1 or (bool) get_config('upgrade_begin');
        $data = [
            'status' => $maintenance ? 'maintenance' : 'ok',
            'eclass_version' => ECLASS_VERSION,
            'api_version' => ApiBootstrap::API_VERSION,
            'api_enabled' => (bool) get_config('ext_apitoken_enabled'),
            'schema_ok' => ApiTokenIdentity::schemaReady(),
        ];
        return ['data' => $data, 'status' => $maintenance ? 503 : 200];
    }
}
