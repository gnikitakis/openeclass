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
 * @brief Class loading for the Integration API.
 *
 * The platform is upgraded by a PHP script, not by Composer, so the API
 * registers its own scoped autoloader instead of relying on a regenerated
 * Composer class map. Only classes named Api* under include/lib/api are
 * handled; everything else is left to the platform.
 */
class ApiBootstrap {

    /** API version served by this code */
    const API_VERSION = '1';

    /**
     * Register the autoloader. Safe to call more than once.
     */
    public static function registerAutoloader() {
        static $registered = false;
        if ($registered) {
            return;
        }
        $registered = true;
        $base = __DIR__;
        spl_autoload_register(function ($class) use ($base) {
            if (!str_starts_with($class, 'Api')) {
                return;
            }
            foreach ([$base, $base . '/Controllers'] as $dir) {
                $file = "$dir/$class.php";
                if (is_file($file)) {
                    require_once $file;
                    return;
                }
            }
        });
        require_once __DIR__ . '/ApiSchema.php';
    }
}
