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
 * @brief Database changes of the Integration API, in one idempotent helper.
 *
 * Every statement is guarded, so the function can run on a fresh install,
 * on an upgraded installation and repeatedly without harm. It is called
 * from the upgrade step of the release that ships the API.
 *
 * Changes to api_token (all additive):
 *  - user_id      the user the token acts as (NULL = legacy api/v1 token)
 *  - scopes       space-separated scope list
 *  - token_hash   SHA-256 of the token; lookups use this column
 *  - token_prefix first characters of the token, for the admin listing
 *  - last_used    last successful authentication
 *
 * @param string $tbl_options Table options string used by the installer/upgrader
 */
function api_token_schema_upgrade($tbl_options) {
    $db = Database::get();

    if (!DBHelper::fieldExists('api_token', 'user_id')) {
        $db->query('ALTER TABLE `api_token` ADD `user_id` INT NULL DEFAULT NULL');
        $db->query('ALTER TABLE `api_token` ADD CONSTRAINT `api_token_user_fk`
            FOREIGN KEY (`user_id`) REFERENCES `user` (`id`) ON DELETE CASCADE');
    }
    if (!DBHelper::fieldExists('api_token', 'scopes')) {
        $db->query('ALTER TABLE `api_token` ADD `scopes` TEXT NULL DEFAULT NULL');
    }
    if (!DBHelper::fieldExists('api_token', 'token_hash')) {
        $db->query('ALTER TABLE `api_token` ADD `token_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL');
    }
    if (!DBHelper::indexExists('api_token', 'api_token_hash')) {
        $db->query('ALTER TABLE `api_token` ADD UNIQUE KEY `api_token_hash` (`token_hash`)');
    }
    if (!DBHelper::fieldExists('api_token', 'token_prefix')) {
        $db->query('ALTER TABLE `api_token` ADD `token_prefix` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL');
    }
    if (!DBHelper::fieldExists('api_token', 'last_used')) {
        $db->query('ALTER TABLE `api_token` ADD `last_used` DATETIME NULL DEFAULT NULL');
    }

    // Backfill hashes for existing tokens so they keep working unchanged
    $db->query('UPDATE `api_token`
        SET `token_hash` = SHA2(`token`, 256), `token_prefix` = LEFT(`token`, 16)
        WHERE `token_hash` IS NULL AND `token` IS NOT NULL AND `token` <> ""');
}
