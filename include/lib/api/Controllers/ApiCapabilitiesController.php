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

require_once 'modules/document/document_service.php';

/**
 * @brief GET /capabilities: what the presenting token can do.
 *
 * An agent that can enumerate its own permissions does not have to guess,
 * and guessing is what produces destructive agent behaviour.
 */
class ApiCapabilitiesController {

    /**
     * @param ApiRequest $request
     * @param ApiContext $context
     * @param array      $params
     * @return array
     */
    public static function get(ApiRequest $request, ApiContext $context, array $params) {
        global $urlServer;
        $data = [
            'api_version' => ApiBootstrap::API_VERSION,
            'eclass_version' => ECLASS_VERSION,
            'user' => [
                'id' => intval($context->user->id),
                'username' => $context->user->username,
                'display_name' => trim($context->user->givenname . ' ' . $context->user->surname),
            ],
            'scopes' => $context->identity->scopes->all(),
            'known_scopes' => ApiScopes::known(),
            'courses' => ApiCourseController::allowedCourses($context),
            'question_types' => [],
            'limits' => [
                'upload_max_bytes' => self::iniBytes(ini_get('upload_max_filesize')),
                'post_max_bytes' => self::iniBytes(ini_get('post_max_size')),
                'base64_upload_max_bytes' => DOCUMENT_SERVICE_MAX_BASE64_BYTES,
                'allowed_extensions' => self::allowedExtensions(),
            ],
            'features' => [
                'dry_run' => true,
                'idempotency' => false,
                'publish' => true,
                'draft_first' => true,
            ],
            'error_codes' => ApiErrorCodes::all(),
            'spec_url' => $urlServer . 'api/integration/v1/index.php?_path=/openapi.yaml',
            'acting' => $context->actingSummary(),
        ];
        return ['data' => $data];
    }

    /**
     * File extensions a teacher of this platform may upload: the student
     * whitelist plus the teacher whitelist. A whitelist of "*" is reported
     * as ["*"]; executable formats are refused even then.
     * @return string[]
     */
    private static function allowedExtensions() {
        $list = [];
        foreach (['student_upload_whitelist', 'teacher_upload_whitelist'] as $key) {
            foreach (explode(',', (string) get_config($key)) as $extension) {
                $extension = trim($extension);
                if ($extension !== '') {
                    $list[$extension] = true;
                }
            }
        }
        $list = array_keys($list);
        sort($list);
        return $list;
    }

    /**
     * @param string $value php.ini size notation, e.g. "256M"
     * @return int
     */
    private static function iniBytes($value) {
        $value = trim((string) $value);
        if ($value === '') {
            return 0;
        }
        $unit = strtolower(substr($value, -1));
        $number = (int) $value;
        switch ($unit) {
            case 'g': return $number * 1024 * 1024 * 1024;
            case 'm': return $number * 1024 * 1024;
            case 'k': return $number * 1024;
            default: return $number;
        }
    }
}
