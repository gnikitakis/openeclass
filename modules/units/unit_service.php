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
 * @file modules/units/unit_service.php
 * @brief Course unit and unit resource persistence, independent of the
 *        request: no $_REQUEST, no redirects, no flash messages.
 *
 * The functions reproduce what modules/units/functions.php and
 * modules/units/insert.php do when a teacher edits units in the interface,
 * with the course id passed explicitly. They exist so that other entry
 * points (the Integration API) write units exactly the way the interface
 * does. The interface handlers are not changed.
 *
 * Every function that changes data ends with the same search indexing and
 * course metadata refresh the interface performs; pass $index = false to
 * skip both (used for transactional dry runs).
 */

require_once 'modules/search/classes/ConstantsUtil.php';
require_once 'modules/search/classes/SearchEngineFactory.php';
require_once 'modules/course_metadata/CourseXML.php';

/** Unit resource types this service can create, keyed by the value stored in unit_resources.type */
const UNIT_RESOURCE_TYPES_SUPPORTED = ['doc', 'text', 'dvdr', 'exercise', 'link'];

/**
 * @brief Create a course unit (functions.php, handle_unit_info_edit, add branch).
 * @param int    $courseId
 * @param string $courseCode
 * @param array  $data  keys: title (string), comments (string, HTML already purified),
 *                      start_week (Y-m-d or null), finish_week (Y-m-d or null),
 *                      visible (0, 1 or 2; default 1 as in the interface)
 * @param bool   $index Run search indexing and metadata refresh
 * @return int The new unit id
 */
function unit_create($courseId, $courseCode, array $data, $index = true) {
    $db = Database::get();
    $max = $db->querySingle('SELECT MAX(`order`) AS max_order FROM course_units WHERE course_id = ?d', $courseId);
    $order = max(0, intval($max->max_order ?? 0)) + 1;
    $q = $db->query('INSERT INTO course_units SET
            title = ?s, comments = ?s, start_week = ?s, finish_week = ?s,
            visible = ?d, assign_to_specific = 0, `order` = ?d, course_id = ?d',
        $data['title'], $data['comments'] ?? '', $data['start_week'] ?? null, $data['finish_week'] ?? null,
        $data['visible'] ?? 1, $order, $courseId);
    $unitId = $q->lastInsertID;
    $count = $db->querySingle('SELECT COUNT(*) AS n FROM course_units WHERE course_id = ?d', $courseId);
    if (intval($count->n) == 1) { // first unit: make 'list' the default unit view
        $db->query('UPDATE course SET view_units = 1 WHERE id = ?d', $courseId);
    }
    if ($index) {
        unit_service_index($courseId, $courseCode, $unitId);
    }
    return $unitId;
}

/**
 * @brief Update the fields of a unit that are present in $data
 *        (functions.php, handle_unit_info_edit, update branch).
 * @param int    $courseId
 * @param string $courseCode
 * @param int    $unitId
 * @param array  $data  any of: title, comments, start_week, finish_week
 * @param bool   $index
 * @return bool False when the unit does not belong to the course
 */
function unit_update($courseId, $courseCode, $unitId, array $data, $index = true) {
    $unit = unit_service_get($courseId, $unitId);
    if (!$unit) {
        return false;
    }
    $title = array_key_exists('title', $data) ? $data['title'] : $unit->title;
    $comments = array_key_exists('comments', $data) ? $data['comments'] : $unit->comments;
    $start = array_key_exists('start_week', $data) ? $data['start_week'] : $unit->start_week;
    $finish = array_key_exists('finish_week', $data) ? $data['finish_week'] : $unit->finish_week;
    Database::get()->query('UPDATE course_units SET title = ?s, comments = ?s, start_week = ?s, finish_week = ?s
        WHERE id = ?d AND course_id = ?d', $title, $comments, $start, $finish, $unitId, $courseId);
    if ($index) {
        unit_service_index($courseId, $courseCode, $unitId);
    }
    return true;
}

/**
 * @brief Set unit visibility (course_home.php, "vis" handler).
 * @param int    $courseId
 * @param string $courseCode
 * @param int    $unitId
 * @param int    $visible 0 or 1
 * @param bool   $index
 * @return bool False when the unit does not belong to the course
 */
function unit_set_visibility($courseId, $courseCode, $unitId, $visible, $index = true) {
    if (!unit_service_get($courseId, $unitId)) {
        return false;
    }
    Database::get()->query('UPDATE course_units SET visible = ?d WHERE id = ?d AND course_id = ?d',
        $visible ? 1 : 0, $unitId, $courseId);
    if ($index) {
        unit_service_index($courseId, $courseCode, $unitId);
    }
    return true;
}

/**
 * @brief Reorder all units of a course. $ids must contain every unit of the
 *        course exactly once, in the desired order.
 * @param int   $courseId
 * @param int[] $ids
 * @return bool False when $ids is not a permutation of the course's units
 */
function unit_reorder($courseId, array $ids) {
    return unit_service_reorder('course_units', 'course_id', $courseId, $ids);
}

/**
 * @brief Add a resource to a unit (insert.php, insert_doc / insert_text /
 *        insert_divider / insert_exercise / insert_link).
 *
 * The referenced document, exercise or link must belong to the course.
 *
 * @param int    $courseId
 * @param string $courseCode
 * @param int    $unitId
 * @param string $type  One of UNIT_RESOURCE_TYPES_SUPPORTED
 * @param array  $data  doc: res_id, title (optional override); text: comments (purified HTML),
 *                      title (optional); dvdr: title (optional); exercise: res_id; link: res_id.
 *                      All types: visible (default 1 as in the interface)
 * @param bool   $index
 * @return int|false The new unit_resources id, or false when the unit or the
 *                   referenced object is not in the course
 */
function unit_resource_add($courseId, $courseCode, $unitId, $type, array $data, $index = true) {
    if (!unit_service_get($courseId, $unitId) or !in_array($type, UNIT_RESOURCE_TYPES_SUPPORTED, true)) {
        return false;
    }
    $db = Database::get();
    $title = $data['title'] ?? '';
    $comments = $data['comments'] ?? '';
    $resId = 0;
    switch ($type) {
        case 'doc':
            $file = $db->querySingle('SELECT id, filename, title, comment FROM document
                WHERE course_id = ?d AND id = ?d', $courseId, $data['res_id'] ?? 0);
            if (!$file) {
                return false;
            }
            $resId = $file->id;
            if ($title === '') {
                $title = empty($file->title) ? $file->filename : $file->title;
            }
            if ($comments === '') {
                $comments = empty($file->comment) ? '' : $file->comment;
            }
            break;
        case 'exercise':
            $exercise = $db->querySingle('SELECT id, title, description FROM exercise
                WHERE course_id = ?d AND id = ?d', $courseId, $data['res_id'] ?? 0);
            if (!$exercise) {
                return false;
            }
            $resId = $exercise->id;
            if ($title === '') {
                $title = $exercise->title;
            }
            if ($comments === '') {
                $comments = (string) $exercise->description;
            }
            break;
        case 'link':
            $link = $db->querySingle('SELECT id, title, description FROM link
                WHERE course_id = ?d AND id = ?d', $courseId, $data['res_id'] ?? 0);
            if (!$link) {
                return false;
            }
            $resId = $link->id;
            if ($title === '') {
                $title = $link->title;
            }
            if ($comments === '') {
                $comments = (string) $link->description;
            }
            break;
        case 'dvdr':
            $comments = '<div class="unit-divider"></div>';
            break;
        case 'text':
            break;
    }
    $max = $db->querySingle('SELECT MAX(`order`) AS max_order FROM unit_resources WHERE unit_id = ?d', $unitId);
    $order = max(0, intval($max->max_order ?? 0)) + 1;
    $q = $db->query('INSERT INTO unit_resources SET unit_id = ?d, type = ?s, title = ?s, comments = ?s,
            visible = ?d, `order` = ?d, `date` = ' . DBHelper::timeAfter() . ', res_id = ?d',
        $unitId, $type, $title, $comments, $data['visible'] ?? 1, $order, $resId);
    $resourceId = $q->lastInsertID;
    if ($index) {
        unit_service_index($courseId, $courseCode, null, $resourceId);
    }
    return $resourceId;
}

/**
 * @brief Update title and/or comments of a unit resource (functions.php,
 *        "edit_res_submit" handler).
 * @param int    $courseId
 * @param string $courseCode
 * @param int    $unitId
 * @param int    $resourceId
 * @param array  $data  any of: title, comments (purified HTML)
 * @param bool   $index
 * @return bool False when the resource is not in the unit or the unit not in the course
 */
function unit_resource_update($courseId, $courseCode, $unitId, $resourceId, array $data, $index = true) {
    $resource = unit_service_get_resource($courseId, $unitId, $resourceId);
    if (!$resource) {
        return false;
    }
    $title = array_key_exists('title', $data) ? $data['title'] : $resource->title;
    $comments = array_key_exists('comments', $data) ? $data['comments'] : $resource->comments;
    Database::get()->query('UPDATE unit_resources SET title = ?s, comments = ?s WHERE unit_id = ?d AND id = ?d',
        $title, $comments, $unitId, $resourceId);
    if ($index) {
        unit_service_index($courseId, $courseCode, null, $resourceId);
    }
    return true;
}

/**
 * @brief Set unit resource visibility (functions.php, "vis" handler).
 * @param int    $courseId
 * @param string $courseCode
 * @param int    $unitId
 * @param int    $resourceId
 * @param int    $visible 0 or 1
 * @return bool False when the resource is not in the unit or the unit not in the course
 */
function unit_resource_set_visibility($courseId, $courseCode, $unitId, $resourceId, $visible) {
    if (!unit_service_get_resource($courseId, $unitId, $resourceId)) {
        return false;
    }
    Database::get()->query('UPDATE unit_resources SET visible = ?d WHERE unit_id = ?d AND id = ?d',
        $visible ? 1 : 0, $unitId, $resourceId);
    return true;
}

/**
 * @brief Reorder all resources of a unit. $ids must contain every resource
 *        of the unit exactly once, in the desired order.
 * @param int   $courseId
 * @param int   $unitId
 * @param int[] $ids
 * @return bool False when the unit is not in the course or $ids is not a permutation
 */
function unit_resource_reorder($courseId, $unitId, array $ids) {
    if (!unit_service_get($courseId, $unitId)) {
        return false;
    }
    return unit_service_reorder('unit_resources', 'unit_id', $unitId, $ids);
}

/**
 * @param int $courseId
 * @param int $unitId
 * @return object|null The unit row when it belongs to the course
 */
function unit_service_get($courseId, $unitId) {
    return Database::get()->querySingle('SELECT * FROM course_units WHERE id = ?d AND course_id = ?d',
        $unitId, $courseId) ?: null;
}

/**
 * @param int $courseId
 * @param int $unitId
 * @param int $resourceId
 * @return object|null The resource row when it belongs to the unit and the unit to the course
 */
function unit_service_get_resource($courseId, $unitId, $resourceId) {
    return Database::get()->querySingle('SELECT unit_resources.* FROM unit_resources
        JOIN course_units ON course_units.id = unit_resources.unit_id
        WHERE unit_resources.id = ?d AND unit_resources.unit_id = ?d AND course_units.course_id = ?d',
        $resourceId, $unitId, $courseId) ?: null;
}

/**
 * @brief Search indexing and course metadata refresh, as the interface does
 *        after every unit change.
 * @param int      $courseId
 * @param string   $courseCode
 * @param int|null $unitId
 * @param int|null $resourceId
 */
function unit_service_index($courseId, $courseCode, $unitId = null, $resourceId = null) {
    $searchEngine = SearchEngineFactory::create();
    if ($unitId) {
        $searchEngine->indexResource(ConstantsUtil::REQUEST_STORE, ConstantsUtil::RESOURCE_UNIT, $unitId);
    }
    if ($resourceId) {
        $searchEngine->indexResource(ConstantsUtil::REQUEST_STORE, ConstantsUtil::RESOURCE_UNITRESOURCE, $resourceId);
    }
    $searchEngine->indexResource(ConstantsUtil::REQUEST_STORE, ConstantsUtil::RESOURCE_COURSE, $courseId);
    CourseXMLElement::refreshCourse($courseId, $courseCode);
}

/**
 * @brief Assign `order` = 1..n to the rows of $table within one parent, in
 *        the order given. Runs in a transaction; the unique (parent, order)
 *        key is respected by passing through negative values.
 * @param string $table       course_units or unit_resources
 * @param string $parentField course_id or unit_id
 * @param int    $parentId
 * @param int[]  $ids
 * @return bool
 */
function unit_service_reorder($table, $parentField, $parentId, array $ids) {
    $db = Database::get();
    $existing = array_map('intval', array_column(
        $db->queryArray("SELECT id FROM `$table` WHERE `$parentField` = ?d", $parentId), 'id'));
    $ids = array_map('intval', $ids);
    if (count($ids) !== count($existing) or count(array_unique($ids)) !== count($ids)
            or array_diff($ids, $existing) or array_diff($existing, $ids)) {
        return false;
    }
    return $db->transaction(function () use ($db, $table, $parentField, $parentId, $ids) {
        foreach ($ids as $position => $id) {
            $db->query("UPDATE `$table` SET `order` = ?d WHERE id = ?d AND `$parentField` = ?d",
                -($position + 1), $id, $parentId);
        }
        foreach ($ids as $position => $id) {
            $db->query("UPDATE `$table` SET `order` = ?d WHERE id = ?d AND `$parentField` = ?d",
                $position + 1, $id, $parentId);
        }
        return Database::TRANSACTION_SUCCESS;
    });
}
