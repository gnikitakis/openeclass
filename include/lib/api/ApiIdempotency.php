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
 * @brief Safe retries for writes: the Idempotency-Key header.
 *
 * A client that lost the answer to a write (timeout, dropped connection)
 * can send the same request again with the same key and receive the
 * original answer instead of creating a second object. Keys are private
 * to the token that used them and expire after a day.
 *
 * Same key, same request: the stored response is replayed, marked with
 * the header Idempotent-Replayed: true.
 * Same key, different request: 409 idempotency_conflict.
 * Same key while the first request is still running: 409 as well.
 *
 * Only successful responses are stored. A request that failed leaves no
 * trace, so the client can correct it and retry with the same key. Dry
 * runs and reads are never stored.
 */
class ApiIdempotency {

    /** Stored responses expire after this many seconds */
    const TTL_SECONDS = 86400;

    /** A request older than this without a stored response is treated as abandoned */
    const PENDING_SECONDS = 120;

    /** @var int|null Row reserved for the current request */
    private static $pendingId = null;

    /** @var bool Whether the current request's response was stored */
    private static $completed = false;

    /**
     * Replay a stored response, refuse a conflicting one, or reserve the key
     * for the current request. Call after the token is validated and before
     * the platform bootstrap.
     * @param ApiRequest       $request
     * @param ApiTokenIdentity $identity
     * @throws ApiException bad_request, idempotency_conflict
     */
    public static function begin(ApiRequest $request, ApiTokenIdentity $identity) {
        $key = self::key($request);
        if ($key === null or $request->method === 'GET' or $request->isDryRun()) {
            return;
        }
        if (!DBHelper::tableExists('api_idempotency')) {
            return;
        }
        $db = Database::get();
        $hash = $request->fingerprint();
        self::cleanup($db);

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $insert = $db->query('INSERT IGNORE INTO api_idempotency (token_id, idem_key, request_hash, created)
                VALUES (?d, ?s, ?s, NOW())', $identity->tokenId, $key, $hash);
            if ($insert and $insert->affectedRows > 0) {
                self::$pendingId = intval($insert->lastInsertID);
                self::arm();
                return;
            }
            $row = $db->querySingle('SELECT id, request_hash, status, body,
                    TIMESTAMPDIFF(SECOND, created, NOW()) AS age
                FROM api_idempotency WHERE token_id = ?d AND idem_key = ?s', $identity->tokenId, $key);
            if (!$row) {
                continue; // deleted between our insert and our read; try once more
            }
            if ($row->request_hash !== $hash) {
                throw new ApiException(ApiErrorCodes::IDEMPOTENCY_CONFLICT,
                    'This Idempotency-Key was already used for a different request');
            }
            if ($row->body === null) {
                if (intval($row->age) > self::PENDING_SECONDS) {
                    $db->query('DELETE FROM api_idempotency WHERE id = ?d', $row->id);
                    continue; // abandoned; take the key over
                }
                throw new ApiException(ApiErrorCodes::IDEMPOTENCY_CONFLICT,
                    'A request with this Idempotency-Key is still being processed');
            }
            ApiResponse::sendRaw($row->body, intval($row->status), ['Idempotent-Replayed' => 'true']);
        }
        throw new ApiException(ApiErrorCodes::INTERNAL, 'Could not reserve the Idempotency-Key');
    }

    /**
     * The validated Idempotency-Key header, or null when absent.
     * @param ApiRequest $request
     * @return string|null
     * @throws ApiException bad_request for an unusable key
     */
    public static function key(ApiRequest $request) {
        $key = $request->header('Idempotency-Key');
        if ($key === null or $key === '') {
            return null;
        }
        if (!preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $key)) {
            throw new ApiException(ApiErrorCodes::BAD_REQUEST,
                'Idempotency-Key must be 1 to 128 characters from A-Z, a-z, 0-9, ".", "_", ":" and "-"');
        }
        return $key;
    }

    /**
     * Store the successful response against the reserved key, and release
     * the key if the request ends without one.
     */
    private static function arm() {
        ApiResponse::setRecorder(function (array $body, $status) {
            if (self::$pendingId === null or $status < 200 or $status >= 300) {
                return;
            }
            Database::get()->query('UPDATE api_idempotency SET status = ?d, body = ?s WHERE id = ?d',
                $status, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), self::$pendingId);
            self::$completed = true;
        });
        register_shutdown_function(function () {
            if (self::$pendingId !== null and !self::$completed) {
                Database::get()->query('DELETE FROM api_idempotency WHERE id = ?d', self::$pendingId);
            }
        });
    }

    /**
     * Expire old keys and old rate limit windows.
     * @param Database $db
     */
    private static function cleanup($db) {
        $db->query('DELETE FROM api_idempotency WHERE created < DATE_SUB(NOW(), INTERVAL ?d SECOND)', self::TTL_SECONDS);
        if (DBHelper::tableExists('api_rate_limit')) {
            $db->query('DELETE FROM api_rate_limit WHERE window_start < DATE_SUB(NOW(), INTERVAL 1 HOUR)');
        }
    }
}
