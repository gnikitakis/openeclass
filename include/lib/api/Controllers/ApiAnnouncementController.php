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

require_once 'modules/announcements/announcement_service.php';

/**
 * @brief Course announcements.
 *
 * Draft-first: announcements are created hidden. Neither creation nor
 * publication through the API sends email; notifying course members stays a
 * decision a person makes in the interface.
 */
class ApiAnnouncementController {

    /**
     * GET /courses/{code}/announcements
     * @param ApiRequest $request
     * @param ApiContext $context
     * @param array      $params
     * @return array
     */
    public static function index(ApiRequest $request, ApiContext $context, array $params) {
        $rows = Database::get()->queryArray('SELECT * FROM announcement WHERE course_id = ?d
            ORDER BY `order` DESC, `date` DESC', $context->courseId);
        return ['data' => array_map(['self', 'json'], $rows)];
    }

    /**
     * POST /courses/{code}/announcements: create a hidden announcement.
     * @param ApiRequest $request
     * @param ApiContext $context
     * @param array      $params
     * @return array
     */
    public static function store(ApiRequest $request, ApiContext $context, array $params) {
        $in = ApiInput::fromRequest($request);
        $in->rejectUnknown(['title', 'content', 'start_display', 'stop_display']);
        $data = [
            'title' => $in->string('title', true, 255),
            'content' => $in->html('content', true),
            'start_display' => $in->dateTime('start_display'),
            'stop_display' => $in->dateTime('stop_display'),
            'visible' => 0,
            'log' => ApiWrite::logDetails($context),
        ];
        $in->check();
        return ApiWrite::run($context, function ($commit) use ($context, $data) {
            $id = announcement_save($context->courseId, $data, null, $commit);
            return ['data' => self::json(self::load($context, $id)),
                'changes' => [['op' => 'insert', 'table' => 'announcement', 'id' => $id]]];
        }, 201);
    }

    /**
     * GET /courses/{code}/announcements/{id}
     * @param ApiRequest $request
     * @param ApiContext $context
     * @param array      $params
     * @return array
     */
    public static function show(ApiRequest $request, ApiContext $context, array $params) {
        return ['data' => self::json(self::load($context, $params['id']))];
    }

    /**
     * PATCH /courses/{code}/announcements/{id}
     * @param ApiRequest $request
     * @param ApiContext $context
     * @param array      $params
     * @return array
     */
    public static function update(ApiRequest $request, ApiContext $context, array $params) {
        $announcement = self::load($context, $params['id']);
        ApiWrite::requirePublishIfVisible($context, 'announcements', $announcement->visible != 0);
        $in = ApiInput::fromRequest($request);
        $in->rejectUnknown(['title', 'content', 'start_display', 'stop_display']);
        $data = [];
        if ($in->has('title')) {
            $data['title'] = $in->string('title', true, 255);
        }
        if ($in->has('content')) {
            $data['content'] = $in->html('content', true);
        }
        if ($in->has('start_display')) {
            $data['start_display'] = $in->dateTime('start_display');
        }
        if ($in->has('stop_display')) {
            $data['stop_display'] = $in->dateTime('stop_display');
        }
        if (!$data) {
            $in->error('title', 'required');
        }
        $in->check();
        $data['log'] = ApiWrite::logDetails($context);
        return ApiWrite::run($context, function ($commit) use ($context, $announcement, $data) {
            announcement_save($context->courseId, $data, $announcement->id, $commit);
            return ['data' => self::json(self::load($context, $announcement->id)),
                'changes' => [['op' => 'update', 'table' => 'announcement', 'id' => intval($announcement->id)]]];
        });
    }

    /**
     * POST /courses/{code}/announcements/{id}/visibility  {visible: bool}. Never sends email.
     * @param ApiRequest $request
     * @param ApiContext $context
     * @param array      $params
     * @return array
     */
    public static function visibility(ApiRequest $request, ApiContext $context, array $params) {
        $announcement = self::load($context, $params['id']);
        $in = ApiInput::fromRequest($request);
        $in->rejectUnknown(['visible']);
        $visible = $in->bool('visible', true);
        $in->check();
        return ApiWrite::run($context, function ($commit) use ($context, $announcement, $visible) {
            announcement_set_visibility($context->courseId, $announcement->id, $visible ? 1 : 0, $commit);
            Log::record($context->courseId, MODULE_ID_ANNOUNCE, LOG_MODIFY,
                ['id' => $announcement->id, 'title' => $announcement->title, 'visible' => $visible, 'email' => false]
                + ApiWrite::logDetails($context));
            return ['data' => self::json(self::load($context, $announcement->id)),
                'changes' => [['op' => 'update', 'table' => 'announcement', 'id' => intval($announcement->id)]]];
        });
    }

    /**
     * @param ApiContext $context
     * @param int|string $id
     * @return object announcement row
     * @throws ApiException not_found
     */
    private static function load(ApiContext $context, $id) {
        $row = announcement_get($context->courseId, intval($id));
        if (!$row) {
            throw new ApiException(ApiErrorCodes::NOT_FOUND,
                "Announcement $id does not exist in course '{$context->courseCode}'");
        }
        return $row;
    }

    /**
     * @param object $a announcement row
     * @return array
     */
    private static function json($a) {
        return [
            'id' => intval($a->id),
            'title' => $a->title,
            'content' => (string) $a->content,
            'visible' => $a->visible != 0,
            'date' => $a->date,
            'start_display' => $a->start_display,
            'stop_display' => $a->stop_display,
            'pinned' => intval($a->order) > 0,
        ];
    }
}
