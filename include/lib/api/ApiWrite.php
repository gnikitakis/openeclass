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
 * @brief Runs every write of the Integration API: one transaction, the
 *        draft-first and publish rules, dry runs and the audit record.
 *
 * Dry run (header X-Dry-Run: true): the write runs inside a transaction
 * that is always rolled back, and search indexing is skipped, so the
 * response shows what would happen while the platform stays unchanged.
 * Database::transaction() commits unless the closure returns
 * TRANSACTION_ERROR, so the outcome is decided here and never by the
 * closure's own return value.
 */
class ApiWrite {

    /**
     * Run a write and produce the controller result.
     *
     * @param ApiContext $context
     * @param callable   $body  function (bool $commit): array  where the array is
     *                          ['data' => mixed, 'changes' => [['op' => ..., 'table' => ..., 'id' => ...]]]
     *                          and $commit is false during a dry run (skip indexing)
     * @param int        $status HTTP status on success
     * @return array Controller result with meta.dry_run and meta.changes
     * @throws ApiException
     */
    public static function run(ApiContext $context, callable $body, $status = 200) {
        $dryRun = $context->request->isDryRun();
        $result = null;
        $failure = null;
        Database::get()->transaction(function () use ($body, $dryRun, &$result, &$failure) {
            try {
                $result = $body(!$dryRun);
            } catch (ApiException $e) {
                $failure = $e;
                return Database::TRANSACTION_ERROR;
            }
            return $dryRun ? Database::TRANSACTION_ERROR : Database::TRANSACTION_SUCCESS;
        });
        if ($failure) {
            throw $failure;
        }
        if (!is_array($result) or !array_key_exists('data', $result)) {
            throw new ApiException(ApiErrorCodes::INTERNAL, 'Write produced no result');
        }
        return [
            'data' => $result['data'],
            'meta' => ['dry_run' => $dryRun, 'changes' => $result['changes'] ?? []],
            'status' => $dryRun ? 200 : $status,
        ];
    }

    /**
     * The publish rule: editing something students can currently see is
     * publishing, and needs the area's publish scope on top of write.
     * @param ApiContext $context
     * @param string     $area    e.g. "units"
     * @param bool       $visible Whether the object is visible to students now
     * @throws ApiException scope_missing
     */
    public static function requirePublishIfVisible(ApiContext $context, $area, $visible) {
        if ($visible) {
            $context->identity->requireScope("$area.publish");
        }
    }

    /**
     * Details every API write attaches to its course log record, so the
     * course log shows the acting teacher, the token and the request.
     * @param ApiContext $context
     * @return array
     */
    public static function logDetails(ApiContext $context) {
        return ['api' => [
            'request_id' => ApiResponse::requestId(),
            'token_id' => $context->identity->tokenId,
            'token_prefix' => $context->identity->tokenPrefix,
        ]];
    }
}
