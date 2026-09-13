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
 * @brief Scope set of an Integration API token.
 *
 * Scopes are "<area>.<level>" strings stored space-separated (OAuth style).
 * Levels imply lower ones within the same area: publish > write > read.
 * A token flagged read_only keeps only its read scopes.
 */
class ApiScopes {

    /** Areas and the levels they support */
    const AREAS = [
        'courses' => ['read'],
        'documents' => ['read', 'write', 'publish'],
        'units' => ['read', 'write', 'publish'],
        'announcements' => ['read', 'write', 'publish'],
        'questions' => ['read', 'write'],
        'exercises' => ['read', 'write', 'publish'],
        'gradebook' => ['read', 'link'],
        'results' => ['read'],
    ];

    /** Level hierarchy: a level grants everything listed for it */
    const IMPLIES = [
        'read' => ['read'],
        'write' => ['write', 'read'],
        'publish' => ['publish', 'write', 'read'],
        'link' => ['link', 'read'],
    ];

    /** @var array<string, true> Effective scopes after expansion */
    private $granted = [];

    /**
     * @param string $spaceSeparated Stored scope string
     * @param bool   $readOnly       Strip every non-read scope
     */
    public function __construct($spaceSeparated, $readOnly = false) {
        foreach (preg_split('/\s+/', trim((string) $spaceSeparated)) as $scope) {
            if ($scope === '' or !str_contains($scope, '.')) {
                continue;
            }
            [$area, $level] = explode('.', $scope, 2);
            if (!isset(self::AREAS[$area]) or !in_array($level, self::AREAS[$area], true)) {
                continue;
            }
            foreach (self::IMPLIES[$level] as $implied) {
                if (in_array($implied, self::AREAS[$area], true)) {
                    $this->granted["$area.$implied"] = true;
                }
            }
        }
        if ($readOnly) {
            foreach (array_keys($this->granted) as $scope) {
                if (!str_ends_with($scope, '.read')) {
                    unset($this->granted[$scope]);
                }
            }
        }
    }

    /**
     * @param string $scope e.g. "documents.write"
     * @return bool
     */
    public function has($scope) {
        return isset($this->granted[$scope]);
    }

    /**
     * @return string[] Sorted list of effective scopes
     */
    public function all() {
        $list = array_keys($this->granted);
        sort($list);
        return $list;
    }

    /**
     * @return string[] Every scope the platform knows, for the capabilities document
     */
    public static function known() {
        $list = [];
        foreach (self::AREAS as $area => $levels) {
            foreach ($levels as $level) {
                $list[] = "$area.$level";
            }
        }
        return $list;
    }
}
