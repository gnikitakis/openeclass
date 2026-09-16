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
 * @brief Per-token request limit of the Integration API, in fixed windows
 *        of one minute, counted in the api_rate_limit table.
 *
 * Reads (GET) and writes (everything else) have separate budgets. The
 * defaults, 600 reads and 120 writes per minute, can be changed with the
 * configuration keys integration_api_rate_read and
 * integration_api_rate_write. Every response carries X-RateLimit-Limit,
 * X-RateLimit-Remaining and X-RateLimit-Reset; a refused request answers
 * 429 with Retry-After.
 *
 * Only requests that presented a valid token are counted, so an attacker
 * without a token cannot exhaust anyone's budget.
 */
class ApiRateLimiter {

    const DEFAULT_READ_PER_MINUTE = 600;
    const DEFAULT_WRITE_PER_MINUTE = 120;

    /**
     * Count the request and refuse it when the budget is spent.
     * @param ApiRequest       $request
     * @param ApiTokenIdentity $identity
     * @throws ApiException rate_limited
     */
    public static function check(ApiRequest $request, ApiTokenIdentity $identity) {
        if (!DBHelper::tableExists('api_rate_limit')) {
            return; // schema not upgraded yet; the token check already reported that
        }
        $kind = $request->method === 'GET' ? 'read' : 'write';
        $limit = self::limit($kind);
        $now = time();
        $windowStart = date('Y-m-d H:i:00', $now);
        $reset = 60 - ($now % 60);

        $db = Database::get();
        $db->query('INSERT INTO api_rate_limit (token_id, kind, window_start, count) VALUES (?d, ?s, ?t, 1)
            ON DUPLICATE KEY UPDATE count = count + 1', $identity->tokenId, $kind, $windowStart);
        $row = $db->querySingle('SELECT count FROM api_rate_limit WHERE token_id = ?d AND kind = ?s AND window_start = ?t',
            $identity->tokenId, $kind, $windowStart);
        $count = intval($row->count ?? 1);

        header('X-RateLimit-Limit: ' . $limit);
        header('X-RateLimit-Remaining: ' . max(0, $limit - $count));
        header('X-RateLimit-Reset: ' . $reset);

        if ($count > $limit) {
            throw (new ApiException(ApiErrorCodes::RATE_LIMITED,
                "The token exceeded its limit of $limit $kind requests per minute"))
                ->withHeaders(['Retry-After' => $reset]);
        }
    }

    /**
     * @param string $kind "read" or "write"
     * @return int Requests per minute
     */
    public static function limit($kind) {
        $configured = intval(get_config('integration_api_rate_' . $kind));
        if ($configured > 0) {
            return $configured;
        }
        return $kind === 'read' ? self::DEFAULT_READ_PER_MINUTE : self::DEFAULT_WRITE_PER_MINUTE;
    }
}
