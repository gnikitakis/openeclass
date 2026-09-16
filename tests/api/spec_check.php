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
 * @file tests/api/spec_check.php
 * @brief Check that include/lib/api/openapi.yaml still describes exactly
 *        the routes the Integration API serves.
 *
 * Run from the platform root, on the command line:
 *   php tests/api/spec_check.php
 *
 * A description that has drifted from the code is worse than none, because
 * a client believes it. This compares the route table with the document in
 * both directions, so a route added without a description, or a
 * description left behind by a route that was removed, fails the check.
 *
 * The document is read as text rather than parsed: the platform ships no
 * YAML parser and this API adds no dependency. The layout the reader
 * relies on (paths at two spaces, methods at four) is checked as well, so
 * a malformed document cannot pass by looking empty.
 *
 * Exit code 0 = the description matches, 1 = it does not.
 */

if (php_sapi_name() !== 'cli') {
    die("Run from the command line.\n");
}

chdir(dirname(dirname(__DIR__)));
require_once 'include/lib/api/ApiBootstrap.php';
ApiBootstrap::registerAutoloader();

$specFile = 'include/lib/api/openapi.yaml';
$failures = 0;

/**
 * @param bool        $condition
 * @param string      $name
 * @param string|null $detail
 */
function scheck($condition, $name, $detail = null) {
    global $failures;
    if ($condition) {
        echo "PASS  $name\n";
    } else {
        $failures++;
        echo "FAIL  $name" . ($detail !== null ? "  ($detail)" : '') . "\n";
    }
}

if (!is_file($specFile)) {
    echo "FAIL  the description document is missing at $specFile\n";
    exit(1);
}
$lines = file($specFile, FILE_IGNORE_NEW_LINES);

// Collect, for every operation: "METHOD /path", its operationId and the
// permission it declares.
$described = [];
$operationIds = [];
$declaredScope = [];   // "METHOD /path" => declared x-required-scope
$currentPath = null;
$currentOp = null;
$inPaths = false;
$tabs = 0;
foreach ($lines as $line) {
    if (str_contains($line, "\t")) {
        $tabs++;
    }
    if (preg_match('/^paths:\s*$/', $line)) {
        $inPaths = true;
        continue;
    }
    if ($inPaths and preg_match('/^[a-z]/', $line)) {
        $inPaths = false; // a new top-level key ends the paths block
    }
    if (!$inPaths) {
        continue;
    }
    if (preg_match('#^  (/\S*):\s*$#', $line, $m)) {
        $currentPath = $m[1];
        $currentOp = null;
    } elseif ($currentPath !== null and preg_match('/^    (get|post|put|patch|delete):\s*$/', $line, $m)) {
        $currentOp = strtoupper($m[1]) . ' ' . $currentPath;
        $described[] = $currentOp;
    } elseif (preg_match('/^\s+operationId:\s*(\S+)\s*$/', $line, $m)) {
        $operationIds[] = $m[1];
    } elseif ($currentOp !== null and preg_match('/^\s+x-required-scope:\s*(\S+)\s*$/', $line, $m)) {
        $declaredScope[$currentOp] = $m[1];
    }
}

scheck($tabs === 0, 'the document uses no tab characters', "$tabs line(s) contain a tab");
scheck(count($described) > 0, 'operations were found in the document');
scheck(count($operationIds) === count(array_unique($operationIds)),
    'every operationId is unique',
    implode(',', array_diff_assoc($operationIds, array_unique($operationIds))));
scheck(count($operationIds) === count($described),
    'every described operation has an operationId',
    count($operationIds) . ' ids for ' . count($described) . ' operations');

// Compare with the route table, in both directions.
$routed = [];
$routedScope = [];
foreach (ApiRouter::ROUTES as [$method, $pattern, $scope, $controller, $action]) {
    $routed[] = "$method $pattern";
    $routedScope["$method $pattern"] = $scope === null ? 'none' : $scope;
}
sort($routed);
$describedSorted = $described;
sort($describedSorted);

$missing = array_diff($routed, $describedSorted);
scheck(!$missing, 'every route the API serves is described', implode('; ', $missing));

$extra = array_diff($describedSorted, $routed);
scheck(!$extra, 'every described operation exists as a route', implode('; ', $extra));

// The permission each operation declares must be the one the router enforces.
$noScope = array_diff($described, array_keys($declaredScope));
scheck(!$noScope, 'every operation declares the permission it needs', implode('; ', $noScope));

$wrong = [];
foreach ($declaredScope as $operation => $declared) {
    if (isset($routedScope[$operation]) and $routedScope[$operation] !== $declared) {
        $wrong[] = "$operation declares $declared but the router requires {$routedScope[$operation]}";
    }
}
scheck(!$wrong, 'every declared permission matches the one the router enforces', implode('; ', $wrong));

// A declared permission must be one the platform actually knows.
$known = array_merge(ApiScopes::known(), ['none', 'any']);
$unknown = array_diff(array_unique(array_values($declaredScope)), $known);
scheck(!$unknown, 'every declared permission is one the platform defines', implode('; ', $unknown));

echo "\n" . ($failures
    ? "$failures check(s) FAILED: the description and the code disagree"
    : count($routed) . ' routes, all described, nothing described that does not exist') . "\n";
exit($failures ? 1 : 0);
