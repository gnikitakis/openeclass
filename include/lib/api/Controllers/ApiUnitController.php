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

require_once 'include/log.class.php';
require_once 'modules/units/unit_service.php';

/**
 * @brief Course units and their resources.
 *
 * Draft-first: everything this controller creates is hidden (visible = 0).
 * Making something visible, or changing something that is visible, needs
 * units.publish (see ApiWrite::requirePublishIfVisible).
 */
class ApiUnitController {

    /** Resource types accepted on create, API name => stored type */
    const RESOURCE_TYPES = [
        'document' => 'doc',
        'text' => 'text',
        'divider' => 'dvdr',
        'exercise' => 'exercise',
        'link' => 'link',
    ];

    /**
     * GET /courses/{code}/units
     * @param ApiRequest $request
     * @param ApiContext $context
     * @param array      $params
     * @return array
     */
    public static function index(ApiRequest $request, ApiContext $context, array $params) {
        $rows = Database::get()->queryArray('SELECT course_units.*,
                (SELECT COUNT(*) FROM unit_resources WHERE unit_id = course_units.id) AS resources_count
            FROM course_units WHERE course_id = ?d ORDER BY `order`', $context->courseId);
        return ['data' => array_map(['self', 'unitJson'], $rows)];
    }

    /**
     * POST /courses/{code}/units: create a hidden unit.
     * @param ApiRequest $request
     * @param ApiContext $context
     * @param array      $params
     * @return array
     */
    public static function store(ApiRequest $request, ApiContext $context, array $params) {
        $in = ApiInput::fromRequest($request);
        $in->rejectUnknown(['title', 'description', 'start_date', 'end_date']);
        $data = [
            'title' => $in->string('title', true, 255),
            'comments' => $in->html('description') ?? '',
            'start_week' => $in->date('start_date'),
            'finish_week' => $in->date('end_date'),
            'visible' => 0,
        ];
        $in->check();
        return ApiWrite::run($context, function ($commit) use ($context, $data) {
            $id = unit_create($context->courseId, $context->courseCode, $data, $commit);
            Log::record($context->courseId, MODULE_ID_UNITS, LOG_INSERT,
                ['id' => $id, 'title' => $data['title']] + ApiWrite::logDetails($context));
            return ['data' => self::unitJson(self::load($context, $id)),
                'changes' => [['op' => 'insert', 'table' => 'course_units', 'id' => $id]]];
        }, 201);
    }

    /**
     * GET /courses/{code}/units/{id}
     * @param ApiRequest $request
     * @param ApiContext $context
     * @param array      $params
     * @return array
     */
    public static function show(ApiRequest $request, ApiContext $context, array $params) {
        $unit = self::load($context, $params['id']);
        $data = self::unitJson($unit);
        $data['resources'] = self::resourceList($unit->id);
        return ['data' => $data];
    }

    /**
     * PATCH /courses/{code}/units/{id}
     * @param ApiRequest $request
     * @param ApiContext $context
     * @param array      $params
     * @return array
     */
    public static function update(ApiRequest $request, ApiContext $context, array $params) {
        $unit = self::load($context, $params['id']);
        ApiWrite::requirePublishIfVisible($context, 'units', $unit->visible != 0);
        $in = ApiInput::fromRequest($request);
        $in->rejectUnknown(['title', 'description', 'start_date', 'end_date']);
        $data = [];
        if ($in->has('title')) {
            $data['title'] = $in->string('title', true, 255);
        }
        if ($in->has('description')) {
            $data['comments'] = $in->html('description') ?? '';
        }
        if ($in->has('start_date')) {
            $data['start_week'] = $in->date('start_date');
        }
        if ($in->has('end_date')) {
            $data['finish_week'] = $in->date('end_date');
        }
        if (!$data) {
            $in->error('title', 'required');
        }
        $in->check();
        return ApiWrite::run($context, function ($commit) use ($context, $unit, $data) {
            unit_update($context->courseId, $context->courseCode, $unit->id, $data, $commit);
            Log::record($context->courseId, MODULE_ID_UNITS, LOG_MODIFY,
                ['id' => $unit->id, 'title' => $data['title'] ?? $unit->title] + ApiWrite::logDetails($context));
            return ['data' => self::unitJson(self::load($context, $unit->id)),
                'changes' => [['op' => 'update', 'table' => 'course_units', 'id' => intval($unit->id)]]];
        });
    }

    /**
     * POST /courses/{code}/units/{id}/visibility  {visible: bool}
     * @param ApiRequest $request
     * @param ApiContext $context
     * @param array      $params
     * @return array
     */
    public static function visibility(ApiRequest $request, ApiContext $context, array $params) {
        $unit = self::load($context, $params['id']);
        $in = ApiInput::fromRequest($request);
        $in->rejectUnknown(['visible']);
        $visible = $in->bool('visible', true);
        $in->check();
        return ApiWrite::run($context, function ($commit) use ($context, $unit, $visible) {
            unit_set_visibility($context->courseId, $context->courseCode, $unit->id, $visible ? 1 : 0, $commit);
            Log::record($context->courseId, MODULE_ID_UNITS, LOG_MODIFY,
                ['id' => $unit->id, 'title' => $unit->title, 'visible' => $visible] + ApiWrite::logDetails($context));
            return ['data' => self::unitJson(self::load($context, $unit->id)),
                'changes' => [['op' => 'update', 'table' => 'course_units', 'id' => intval($unit->id)]]];
        });
    }

    /**
     * POST /courses/{code}/units/reorder  {ids: [..]} with every unit of the course
     * @param ApiRequest $request
     * @param ApiContext $context
     * @param array      $params
     * @return array
     */
    public static function reorder(ApiRequest $request, ApiContext $context, array $params) {
        $in = ApiInput::fromRequest($request);
        $in->rejectUnknown(['ids']);
        $ids = $in->idList('ids');
        $in->check();
        return ApiWrite::run($context, function ($commit) use ($context, $ids) {
            if (!unit_reorder($context->courseId, $ids)) {
                throw new ApiException(ApiErrorCodes::VALIDATION_FAILED,
                    'ids must list every unit of the course exactly once', [['field' => 'ids', 'code' => 'invalid']]);
            }
            if ($commit) {
                unit_service_index($context->courseId, $context->courseCode);
            }
            Log::record($context->courseId, MODULE_ID_UNITS, LOG_MODIFY,
                ['reorder' => $ids] + ApiWrite::logDetails($context));
            $result = self::index($context->request, $context, []);
            return ['data' => $result['data'],
                'changes' => [['op' => 'update', 'table' => 'course_units']]];
        });
    }

    /**
     * GET /courses/{code}/units/{id}/resources
     * @param ApiRequest $request
     * @param ApiContext $context
     * @param array      $params
     * @return array
     */
    public static function resources(ApiRequest $request, ApiContext $context, array $params) {
        $unit = self::load($context, $params['id']);
        return ['data' => self::resourceList($unit->id)];
    }

    /**
     * POST /courses/{code}/units/{id}/resources: add a hidden resource.
     * Body: {type: document|text|divider|exercise|link, ...} see RESOURCE_TYPES.
     * @param ApiRequest $request
     * @param ApiContext $context
     * @param array      $params
     * @return array
     */
    public static function addResource(ApiRequest $request, ApiContext $context, array $params) {
        $unit = self::load($context, $params['id']);
        $in = ApiInput::fromRequest($request);
        $type = $in->oneOf('type', array_keys(self::RESOURCE_TYPES), true);
        $data = ['visible' => 0];
        $refField = null;
        switch ($type) {
            case 'document':
                $in->rejectUnknown(['type', 'document_id', 'title', 'comments']);
                $refField = 'document_id';
                $data['res_id'] = $in->id('document_id', true);
                $data['title'] = $in->string('title', false, 255) ?? '';
                $data['comments'] = $in->html('comments') ?? '';
                break;
            case 'exercise':
                $in->rejectUnknown(['type', 'exercise_id', 'title', 'comments']);
                $refField = 'exercise_id';
                $data['res_id'] = $in->id('exercise_id', true);
                $data['title'] = $in->string('title', false, 255) ?? '';
                $data['comments'] = $in->html('comments') ?? '';
                break;
            case 'link':
                $in->rejectUnknown(['type', 'link_id', 'title', 'comments']);
                $refField = 'link_id';
                $data['res_id'] = $in->id('link_id', true);
                $data['title'] = $in->string('title', false, 255) ?? '';
                $data['comments'] = $in->html('comments') ?? '';
                break;
            case 'text':
                $in->rejectUnknown(['type', 'title', 'html']);
                $data['title'] = $in->string('title', false, 255) ?? '';
                $data['comments'] = $in->html('html', true) ?? '';
                break;
            case 'divider':
                $in->rejectUnknown(['type', 'title']);
                $data['title'] = $in->string('title', false, 255) ?? '';
                break;
        }
        $in->check();
        $storedType = self::RESOURCE_TYPES[$type];
        return ApiWrite::run($context, function ($commit) use ($context, $unit, $storedType, $data, $refField) {
            $id = unit_resource_add($context->courseId, $context->courseCode, $unit->id, $storedType, $data, $commit);
            if ($id === false) {
                throw new ApiException(ApiErrorCodes::NOT_FOUND,
                    "The $refField does not refer to an object of course '{$context->courseCode}'");
            }
            Log::record($context->courseId, MODULE_ID_UNITS, LOG_INSERT,
                ['unit_id' => $unit->id, 'resource_id' => $id, 'type' => $storedType] + ApiWrite::logDetails($context));
            return ['data' => self::resourceJson(self::loadResource($context, $unit->id, $id)),
                'changes' => [['op' => 'insert', 'table' => 'unit_resources', 'id' => $id]]];
        }, 201);
    }

    /**
     * PATCH /courses/{code}/units/{id}/resources/{rid}  {title, comments}
     * @param ApiRequest $request
     * @param ApiContext $context
     * @param array      $params
     * @return array
     */
    public static function updateResource(ApiRequest $request, ApiContext $context, array $params) {
        $unit = self::load($context, $params['id']);
        $resource = self::loadResource($context, $unit->id, $params['rid']);
        ApiWrite::requirePublishIfVisible($context, 'units', $unit->visible != 0 and $resource->visible != 0);
        $in = ApiInput::fromRequest($request);
        $in->rejectUnknown(['title', 'comments']);
        $data = [];
        if ($in->has('title')) {
            $data['title'] = $in->string('title', false, 255) ?? '';
        }
        if ($in->has('comments')) {
            $data['comments'] = $in->html('comments') ?? '';
        }
        if (!$data) {
            $in->error('title', 'required');
        }
        $in->check();
        return ApiWrite::run($context, function ($commit) use ($context, $unit, $resource, $data) {
            unit_resource_update($context->courseId, $context->courseCode, $unit->id, $resource->id, $data, $commit);
            Log::record($context->courseId, MODULE_ID_UNITS, LOG_MODIFY,
                ['unit_id' => $unit->id, 'resource_id' => $resource->id] + ApiWrite::logDetails($context));
            return ['data' => self::resourceJson(self::loadResource($context, $unit->id, $resource->id)),
                'changes' => [['op' => 'update', 'table' => 'unit_resources', 'id' => intval($resource->id)]]];
        });
    }

    /**
     * POST /courses/{code}/units/{id}/resources/{rid}/visibility  {visible: bool}
     * @param ApiRequest $request
     * @param ApiContext $context
     * @param array      $params
     * @return array
     */
    public static function resourceVisibility(ApiRequest $request, ApiContext $context, array $params) {
        $unit = self::load($context, $params['id']);
        $resource = self::loadResource($context, $unit->id, $params['rid']);
        $in = ApiInput::fromRequest($request);
        $in->rejectUnknown(['visible']);
        $visible = $in->bool('visible', true);
        $in->check();
        return ApiWrite::run($context, function ($commit) use ($context, $unit, $resource, $visible) {
            unit_resource_set_visibility($context->courseId, $context->courseCode, $unit->id, $resource->id, $visible ? 1 : 0);
            Log::record($context->courseId, MODULE_ID_UNITS, LOG_MODIFY,
                ['unit_id' => $unit->id, 'resource_id' => $resource->id, 'visible' => $visible] + ApiWrite::logDetails($context));
            return ['data' => self::resourceJson(self::loadResource($context, $unit->id, $resource->id)),
                'changes' => [['op' => 'update', 'table' => 'unit_resources', 'id' => intval($resource->id)]]];
        });
    }

    /**
     * POST /courses/{code}/units/{id}/resources/reorder  {ids: [..]} with every resource of the unit
     * @param ApiRequest $request
     * @param ApiContext $context
     * @param array      $params
     * @return array
     */
    public static function reorderResources(ApiRequest $request, ApiContext $context, array $params) {
        $unit = self::load($context, $params['id']);
        $in = ApiInput::fromRequest($request);
        $in->rejectUnknown(['ids']);
        $ids = $in->idList('ids');
        $in->check();
        return ApiWrite::run($context, function ($commit) use ($context, $unit, $ids) {
            if (!unit_resource_reorder($context->courseId, $unit->id, $ids)) {
                throw new ApiException(ApiErrorCodes::VALIDATION_FAILED,
                    'ids must list every resource of the unit exactly once', [['field' => 'ids', 'code' => 'invalid']]);
            }
            if ($commit) {
                unit_service_index($context->courseId, $context->courseCode, $unit->id);
            }
            Log::record($context->courseId, MODULE_ID_UNITS, LOG_MODIFY,
                ['unit_id' => $unit->id, 'reorder' => $ids] + ApiWrite::logDetails($context));
            return ['data' => self::resourceList($unit->id),
                'changes' => [['op' => 'update', 'table' => 'unit_resources']]];
        });
    }

    /**
     * @param ApiContext $context
     * @param int|string $id
     * @return object course_units row with resources_count
     * @throws ApiException not_found
     */
    private static function load(ApiContext $context, $id) {
        $unit = Database::get()->querySingle('SELECT course_units.*,
                (SELECT COUNT(*) FROM unit_resources WHERE unit_id = course_units.id) AS resources_count
            FROM course_units WHERE id = ?d AND course_id = ?d', intval($id), $context->courseId);
        if (!$unit) {
            throw new ApiException(ApiErrorCodes::NOT_FOUND, "Unit $id does not exist in course '{$context->courseCode}'");
        }
        return $unit;
    }

    /**
     * @param ApiContext $context
     * @param int        $unitId
     * @param int|string $id
     * @return object unit_resources row
     * @throws ApiException not_found
     */
    private static function loadResource(ApiContext $context, $unitId, $id) {
        $resource = unit_service_get_resource($context->courseId, $unitId, intval($id));
        if (!$resource) {
            throw new ApiException(ApiErrorCodes::NOT_FOUND, "Resource $id does not exist in unit $unitId");
        }
        return $resource;
    }

    /**
     * @param int $unitId
     * @return array
     */
    private static function resourceList($unitId) {
        $rows = Database::get()->queryArray('SELECT * FROM unit_resources WHERE unit_id = ?d ORDER BY `order`', $unitId);
        return array_map(['self', 'resourceJson'], $rows);
    }

    /**
     * @param object $unit
     * @return array
     */
    private static function unitJson($unit) {
        return [
            'id' => intval($unit->id),
            'title' => $unit->title,
            'description' => (string) $unit->comments,
            'visible' => $unit->visible != 0,
            'start_date' => $unit->start_week,
            'end_date' => $unit->finish_week,
            'order' => intval($unit->order),
            'resources_count' => intval($unit->resources_count ?? 0),
        ];
    }

    /**
     * @param object $r unit_resources row
     * @return array
     */
    private static function resourceJson($r) {
        $apiType = array_search($r->type, self::RESOURCE_TYPES, true) ?: $r->type;
        switch ($r->type) {
            case 'doc': $ref = ['document_id' => intval($r->res_id)]; break;
            case 'exercise': $ref = ['exercise_id' => intval($r->res_id)]; break;
            case 'link': $ref = ['link_id' => intval($r->res_id)]; break;
            case 'text':
            case 'dvdr': $ref = []; break;
            default: $ref = ['res_id' => intval($r->res_id)];
        }
        return [
            'id' => intval($r->id),
            'type' => $apiType,
            'title' => $r->title,
            'comments' => (string) $r->comments,
            'visible' => $r->visible != 0,
            'order' => intval($r->order),
            'date' => $r->date,
            'ref' => $ref ?: new stdClass(),
        ];
    }
}
