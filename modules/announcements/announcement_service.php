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
 * @file modules/announcements/announcement_service.php
 * @brief Course announcement persistence, independent of the request.
 *
 * Reproduces the database part of modules/announcements/submit.php (insert
 * and update) and of index.php (visibility), with the course id passed
 * explicitly. Sending email to course members is deliberately not part of
 * the service: it stays in submit.php, where a person chose recipients.
 */

require_once 'include/log.class.php';
require_once 'modules/search/classes/ConstantsUtil.php';
require_once 'modules/search/classes/SearchEngineFactory.php';

/**
 * @brief Insert or update an announcement (submit.php, lines after validation).
 * @param int      $courseId
 * @param array    $data keys: title (string), content (purified HTML),
 *                       start_display (Y-m-d H:i:s or null), stop_display (Y-m-d H:i:s or null),
 *                       visible (0 or 1), log (array merged into the course log record's
 *                       details). On update, absent keys keep their value.
 * @param int|null $id   Announcement to update, or null to insert
 * @param bool     $index Run search indexing
 * @return int|false The announcement id, or false when $id is not in the course
 */
function announcement_save($courseId, array $data, $id = null, $index = true) {
    $db = Database::get();
    if ($id !== null) {
        $current = announcement_get($courseId, $id);
        if (!$current) {
            return false;
        }
        $title = array_key_exists('title', $data) ? $data['title'] : $current->title;
        $content = array_key_exists('content', $data) ? $data['content'] : $current->content;
        $start = array_key_exists('start_display', $data) ? $data['start_display'] : $current->start_display;
        $stop = array_key_exists('stop_display', $data) ? $data['stop_display'] : $current->stop_display;
        $visible = array_key_exists('visible', $data) ? $data['visible'] : $current->visible;
        $date = is_null($start) ? date('Y-m-d H:i:s') : $start;
        $db->query('UPDATE announcement SET content = ?s, title = ?s, `date` = ?t,
                start_display = ?t, stop_display = ?t, visible = ?d
            WHERE id = ?d AND course_id = ?d',
            $content, $title, $date, $start, $stop, $visible ? 1 : 0, $id, $courseId);
        $logType = LOG_MODIFY;
    } else {
        $title = $data['title'];
        $content = $data['content'] ?? '';
        $start = $data['start_display'] ?? null;
        $stop = $data['stop_display'] ?? null;
        $visible = $data['visible'] ?? 0;
        $date = is_null($start) ? date('Y-m-d H:i:s') : $start;
        $id = $db->query('INSERT INTO announcement SET content = ?s, title = ?s, `date` = ?t,
                course_id = ?d, `order` = 0, start_display = ?t, stop_display = ?t, visible = ?d',
            $content, $title, $date, $courseId, $start, $stop, $visible ? 1 : 0)->lastInsertID;
        $id = intval($id);
        $logType = LOG_INSERT;
    }
    if ($index) {
        $searchEngine = SearchEngineFactory::create();
        $searchEngine->indexResource(ConstantsUtil::REQUEST_STORE, ConstantsUtil::RESOURCE_ANNOUNCEMENT, $id);
    }
    Log::record($courseId, MODULE_ID_ANNOUNCE, $logType, array_merge([
        'id' => $id,
        'email' => false,
        'title' => $title,
        'content' => ellipsize_html(canonicalize_whitespace(strip_tags($content)), 50, '+'),
    ], $data['log'] ?? []));
    return $id;
}

/**
 * @brief Set announcement visibility (index.php, action "visible"). No email is sent.
 * @param int  $courseId
 * @param int  $id
 * @param int  $visible 0 or 1
 * @param bool $index
 * @return bool False when the announcement is not in the course
 */
function announcement_set_visibility($courseId, $id, $visible, $index = true) {
    if (!announcement_get($courseId, $id)) {
        return false;
    }
    Database::get()->query('UPDATE announcement SET visible = ?d WHERE id = ?d AND course_id = ?d',
        $visible ? 1 : 0, $id, $courseId);
    if ($index) {
        $searchEngine = SearchEngineFactory::create();
        $searchEngine->indexResource(ConstantsUtil::REQUEST_STORE, ConstantsUtil::RESOURCE_ANNOUNCEMENT, $id);
    }
    return true;
}

/**
 * @param int $courseId
 * @param int $id
 * @return object|null The announcement row when it belongs to the course
 */
function announcement_get($courseId, $id) {
    return Database::get()->querySingle('SELECT * FROM announcement WHERE id = ?d AND course_id = ?d',
        $id, $courseId) ?: null;
}
