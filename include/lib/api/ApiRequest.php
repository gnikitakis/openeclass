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
 * @brief The incoming HTTP request as seen by the Integration API.
 *
 * Resolves the route path from three sources so the API works on stock
 * Apache, stock nginx and behind reverse proxies without server changes:
 *   1. the documented escape hatch  ?_path=/courses/CODE
 *   2. PATH_INFO                     /index.php/courses/CODE
 *   3. REQUEST_URI minus the script  /api/integration/v1/courses/CODE
 */
class ApiRequest {

    /** @var string GET, POST, PUT, PATCH, DELETE */
    public $method;

    /** @var string Normalised route path, always starting with '/', no trailing slash */
    public $path;

    /** @var array Query parameters without the routing parameter */
    public $query;

    /** @var array<string, string> Request headers, lower-case names */
    public $headers;

    /** @var string|null Raw request body (lazy) */
    private $rawBody = null;

    /** @var array|null Decoded JSON body (lazy) */
    private $jsonBody = null;

    /**
     * Build the request object from PHP's superglobals.
     * @return ApiRequest
     */
    public static function fromGlobals() {
        $r = new ApiRequest();
        $r->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $r->headers = self::collectHeaders();
        $r->query = $_GET;
        unset($r->query['_path']);
        $r->path = self::resolvePath();
        return $r;
    }

    /**
     * Bearer token from the Authorization header, if any.
     * @return string|null
     */
    public function bearerToken() {
        $auth = $this->headers['authorization'] ?? '';
        if (preg_match('/^Bearer\s+(\S+)$/i', trim($auth), $m)) {
            return $m[1];
        }
        return null;
    }

    /**
     * @param string $name Header name, case-insensitive
     * @return string|null
     */
    public function header($name) {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * True when the client asked for a dry run (X-Dry-Run: true).
     * @return bool
     */
    public function isDryRun() {
        $v = strtolower(trim($this->header('X-Dry-Run') ?? ''));
        return $v === 'true' or $v === '1';
    }

    /**
     * Raw request body.
     * @return string
     */
    public function rawBody() {
        if ($this->rawBody === null) {
            $this->rawBody = file_get_contents('php://input');
            if ($this->rawBody === false) {
                $this->rawBody = '';
            }
        }
        return $this->rawBody;
    }

    /**
     * Decoded JSON object body. An empty body is an empty array; a body that
     * is not a JSON object is a bad_request error.
     * @return array
     * @throws ApiException
     */
    public function jsonBody() {
        if ($this->jsonBody === null) {
            $raw = trim($this->rawBody());
            if ($raw === '') {
                $this->jsonBody = [];
            } else {
                $decoded = json_decode($raw, true);
                if (!is_array($decoded)) {
                    throw new ApiException(ApiErrorCodes::BAD_REQUEST, 'Request body must be a JSON object');
                }
                $this->jsonBody = $decoded;
            }
        }
        return $this->jsonBody;
    }

    /**
     * A digest of everything that makes this request what it is: method,
     * path, query and body. For a multipart body, which PHP consumes
     * before the script runs, the form fields and the uploaded bytes are
     * hashed instead.
     * @return string SHA-256 hex digest
     */
    public function fingerprint() {
        $query = $this->query;
        ksort($query);
        $parts = [$this->method, $this->path, http_build_query($query)];
        $contentType = strtolower($this->header('Content-Type') ?? '');
        if (str_starts_with($contentType, 'multipart/form-data')) {
            $post = $_POST;
            ksort($post);
            $parts[] = http_build_query($post);
            foreach ($_FILES as $field => $file) {
                $tmp = $file['tmp_name'] ?? '';
                $parts[] = $field . ':' . ($file['name'] ?? '') . ':' . (is_file($tmp) ? hash_file('sha256', $tmp) : '');
            }
        } else {
            $parts[] = $this->rawBody();
        }
        return hash('sha256', implode("\n", $parts));
    }

    /**
     * @return string
     */
    private static function resolvePath() {
        if (isset($_GET['_path']) and $_GET['_path'] !== '') {
            $path = $_GET['_path'];
        } elseif (!empty($_SERVER['PATH_INFO'])) {
            $path = $_SERVER['PATH_INFO'];
        } else {
            $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
            $script = $_SERVER['SCRIPT_NAME'] ?? '';
            $dir = rtrim(dirname($script), '/\\');
            if ($script !== '' and str_starts_with($uri, $script)) {
                $path = substr($uri, strlen($script));
            } elseif ($dir !== '' and str_starts_with($uri, $dir)) {
                $path = substr($uri, strlen($dir));
            } else {
                $path = '/';
            }
        }
        $path = '/' . trim(rawurldecode($path), '/');
        return $path === '//' ? '/' : $path;
    }

    /**
     * @return array<string, string>
     */
    private static function collectHeaders() {
        $headers = [];
        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $value) {
                $headers[strtolower($name)] = $value;
            }
        }
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                if (!isset($headers[$name])) {
                    $headers[$name] = $value;
                }
            }
        }
        if (!isset($headers['authorization']) and isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $headers['authorization'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }
        return $headers;
    }
}
