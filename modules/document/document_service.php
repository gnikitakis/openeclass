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
 * @file modules/document/document_service.php
 * @brief Course document persistence and upload validation, independent of
 *        the request: no $_FILES, no $_POST, no redirects, no exit.
 *
 * The storing functions reproduce what modules/document/index.php and
 * make_path() in include/lib/fileUploadLib.inc.php do when a teacher
 * uploads a file or creates a folder, with the course and the target
 * directory passed explicitly. modules/document/index.php is not changed.
 *
 * One check is added that the interface does not perform today:
 * document_service_content_check() compares the bytes of an uploaded file
 * with the type its extension claims (finfo_file), and refuses a file whose
 * content is a script whatever its name. The interface trusts the extension
 * and the browser's Content-Type (add_ext_on_mime), so a file named .pdf
 * whose bytes are PHP is accepted there and refused here.
 */

require_once 'include/log.class.php';
require_once 'include/lib/fileUploadLib.inc.php';
require_once 'modules/search/classes/ConstantsUtil.php';
require_once 'modules/search/classes/SearchEngineFactory.php';

/** Largest file accepted as base64 inside a JSON request body */
const DOCUMENT_SERVICE_MAX_BASE64_BYTES = 20971520;

/**
 * Content types accepted for each extension. An extension that is absent is
 * not checked against a type, only against the script test below. Several
 * formats are containers (the Office and OpenDocument formats and EPUB are
 * ZIP archives) and are reported differently depending on the age of the
 * magic database, so each entry is a list.
 */
const DOCUMENT_SERVICE_CONTENT_TYPES = [
    'pdf' => ['application/pdf'],
    'doc' => ['application/msword', 'application/vnd.ms-office', 'application/x-ole-storage', 'application/CDFV2'],
    'xls' => ['application/vnd.ms-excel', 'application/vnd.ms-office', 'application/x-ole-storage', 'application/CDFV2'],
    'ppt' => ['application/vnd.ms-powerpoint', 'application/vnd.ms-office', 'application/x-ole-storage', 'application/CDFV2'],
    'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
    'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
    'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
    'odt' => ['application/vnd.oasis.opendocument.text', 'application/zip'],
    'ods' => ['application/vnd.oasis.opendocument.spreadsheet', 'application/zip'],
    'odp' => ['application/vnd.oasis.opendocument.presentation', 'application/zip'],
    'epub' => ['application/epub+zip', 'application/zip'],
    'zip' => ['application/zip'],
    'gz' => ['application/gzip', 'application/x-gzip'],
    'tar' => ['application/x-tar'],
    'rar' => ['application/x-rar', 'application/vnd.rar', 'application/x-rar-compressed'],
    '7z' => ['application/x-7z-compressed'],
    'rtf' => ['application/rtf', 'text/rtf'],
    'txt' => ['text/plain'],
    'md' => ['text/plain', 'text/markdown'],
    'csv' => ['text/plain', 'text/csv', 'application/csv'],
    'json' => ['text/plain', 'application/json'],
    'xml' => ['text/xml', 'application/xml', 'text/plain'],
    'html' => ['text/html', 'text/plain'],
    'htm' => ['text/html', 'text/plain'],
    'jpg' => ['image/jpeg'],
    'jpeg' => ['image/jpeg'],
    'png' => ['image/png'],
    'gif' => ['image/gif'],
    'bmp' => ['image/bmp', 'image/x-ms-bmp'],
    'webp' => ['image/webp'],
    'tif' => ['image/tiff'],
    'tiff' => ['image/tiff'],
    'svg' => ['image/svg+xml', 'text/xml', 'application/xml', 'text/plain', 'text/html'],
    'mp3' => ['audio/mpeg'],
    'wav' => ['audio/x-wav', 'audio/wav'],
    'ogg' => ['audio/ogg', 'video/ogg', 'application/ogg'],
    'mka' => ['audio/x-matroska', 'video/x-matroska'],
    'mp4' => ['video/mp4', 'application/mp4'],
    'm4v' => ['video/mp4', 'video/x-m4v'],
    'webm' => ['video/webm', 'audio/webm'],
    'mkv' => ['video/x-matroska'],
    'avi' => ['video/x-msvideo', 'video/avi'],
    'mov' => ['video/quicktime'],
];

/**
 * Formats whose content is text by nature: a code example inside one of
 * these is ordinary teaching material, so the raw content test below is not
 * applied to them. What the web server would actually execute is decided by
 * the extension, and isWhitelistAllowed() blocks those unconditionally.
 */
const DOCUMENT_SERVICE_TEXT_FORMATS = ['txt', 'md', 'csv', 'json', 'xml', 'html', 'htm', 'js', 'css', 'tex', 'log'];

/** Content types that are never accepted, whatever the extension or whitelist */
const DOCUMENT_SERVICE_FORBIDDEN_TYPES = [
    'text/x-php', 'application/x-php', 'application/x-httpd-php', 'application/php',
    'text/x-perl', 'text/x-python', 'text/x-shellscript', 'application/x-shellscript',
    'application/x-executable', 'application/x-dosexec', 'application/x-mach-binary',
    'application/x-sharedlib', 'application/vnd.microsoft.portable-executable',
];

/**
 * @brief Whether the file name is allowed here.
 *
 * Wraps isWhitelistAllowed(), which reads $is_editor, $is_admin and $uid,
 * so the whitelist of the acting user applies. Unlike validateUploadedFile()
 * this reports the answer instead of ending the request.
 *
 * @param string $filename
 * @return bool
 */
function document_service_name_allowed($filename) {
    return isWhitelistAllowed($filename);
}

/**
 * @brief Compare the bytes of a file with the type its name claims.
 *
 * @param string $path     Readable path of the file to inspect
 * @param string $filename Name the file will be stored under
 * @return string|null Null when the content is acceptable, otherwise one of
 *                     'file_type_not_allowed' (the content is a script or an
 *                     executable) or 'mime_mismatch' (the content is not what
 *                     the extension claims)
 */
function document_service_content_check($path, $filename) {
    if (!is_file($path) or !is_readable($path)) {
        return 'file_type_not_allowed';
    }
    $detected = '';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $detected = (string) finfo_file($finfo, $path);
            finfo_close($finfo);
        }
    }
    $detected = strtolower(trim(explode(';', $detected)[0]));
    $extension = strtolower(getPureFileExtension($filename));

    // A script is refused whatever it is called, and whatever the whitelist
    // allows: an installation whose whitelist is "*" is protected as well.
    if (in_array($detected, DOCUMENT_SERVICE_FORBIDDEN_TYPES, true)) {
        return 'file_type_not_allowed';
    }
    if (!in_array($extension, DOCUMENT_SERVICE_TEXT_FORMATS, true)) {
        $head = (string) file_get_contents($path, false, null, 0, 1024);
        if (preg_match('/<\?php|<\?=/i', $head) or preg_match('/^#!.*\b(php|perl|python|sh|bash)\b/i', $head)) {
            return 'file_type_not_allowed';
        }
    }

    if ($detected === '' or $detected === 'application/octet-stream') {
        return null; // nothing reliable to compare with
    }
    if (!isset(DOCUMENT_SERVICE_CONTENT_TYPES[$extension])) {
        return null; // extension the platform allows but this check does not know
    }
    return in_array($detected, DOCUMENT_SERVICE_CONTENT_TYPES[$extension], true) ? null : 'mime_mismatch';
}

/**
 * @param string $courseCode
 * @return string Absolute path of the course document directory
 */
function document_service_basedir($courseCode) {
    global $webDir;
    return $webDir . '/courses/' . $courseCode . '/document';
}

/**
 * @param int $courseId
 * @return int Document quota of the course in bytes
 */
function document_service_quota($courseId) {
    $r = Database::get()->querySingle('SELECT doc_quota FROM course WHERE id = ?d', $courseId);
    return intval($r->doc_quota ?? 0);
}

/**
 * @param string $basedir
 * @return int Bytes currently used by the course documents
 */
function document_service_disk_used($basedir) {
    return is_dir($basedir) ? intval(dir_total_space($basedir)) : 0;
}

/**
 * @param int $courseId
 * @param int $id
 * @return object|null The document row when it belongs to the course main document area
 */
function document_service_get($courseId, $id) {
    return Database::get()->querySingle('SELECT * FROM document
        WHERE id = ?d AND course_id = ?d AND subsystem = ?d', $id, $courseId, MAIN) ?: null;
}

/**
 * @param int         $courseId
 * @param string      $parentPath Path of the parent folder, '' for the top level
 * @param string|null $filename   Restrict to this name
 * @return array Rows directly inside the folder, folders first then names
 */
function document_service_list($courseId, $parentPath = '', $filename = null) {
    $sql = 'SELECT * FROM document WHERE course_id = ?d AND subsystem = ?d AND path REGEXP ?s';
    $args = [$courseId, MAIN, '^' . $parentPath . '/[^/]+$'];
    if ($filename !== null) {
        $sql .= ' AND filename = ?s';
        $args[] = $filename;
    }
    $sql .= " ORDER BY format = '.dir' DESC, filename";
    return Database::get()->queryArray($sql, $args);
}

/**
 * @brief Store an uploaded file as a course document (index.php, upload branch).
 *
 * The caller has already validated the name, the content and the quota.
 *
 * @param int         $courseId
 * @param string      $courseCode
 * @param string      $parentPath Path of the target folder, '' for the top level
 * @param string|null $sourcePath File to copy in, or null to record the row only (dry run)
 * @param string      $filename   Name shown to users
 * @param array       $data       keys: title, comment, visible (default 0), copyrighted
 * @param bool        $index      Run search indexing and the document timestamp
 * @return int|false The new document id, or false when the copy failed
 */
function document_service_store($courseId, $courseCode, $parentPath, $sourcePath, $filename, array $data, $index = true) {
    global $language, $uid, $session;
    $basedir = document_service_basedir($courseCode);
    $filename = canonicalize_whitespace($filename);
    $format = get_file_extension($filename);
    $filePath = $parentPath . '/' . safe_filename($format);
    if ($sourcePath !== null and !@copy($sourcePath, $basedir . $filePath)) {
        return false;
    }
    $now = date('Y-m-d H:i:s');
    $id = Database::get()->query('INSERT INTO document SET
            course_id = ?d, subsystem = ?d, subsystem_id = NULL, path = ?s, extra_path = \'\',
            filename = ?s, visible = ?d, comment = ?s, title = ?s, date = ?t, date_modified = ?t,
            format = ?s, language = ?s, copyrighted = ?d, lock_user_id = ?d',
        $courseId, MAIN, $filePath, $filename, $data['visible'] ?? 0,
        $data['comment'] ?? '', $data['title'] ?? '', $now, $now,
        $format, $language, $data['copyrighted'] ?? 0, $uid)->lastInsertID;
    $id = intval($id);
    Log::record($courseId, MODULE_ID_DOCS, LOG_INSERT, array_merge([
        'id' => $id,
        'filepath' => $filePath,
        'filename' => $filename,
        'comment' => $data['comment'] ?? '',
        'title' => $data['title'] ?? '',
    ], $data['log'] ?? []));
    if ($index) {
        $searchEngine = SearchEngineFactory::create();
        $searchEngine->indexResource(ConstantsUtil::REQUEST_STORE, ConstantsUtil::RESOURCE_DOCUMENT, $id);
        if (isset($session)) {
            $session->setDocumentTimestamp($courseId);
        }
    }
    return $id;
}

/**
 * @brief Create a folder (make_path() in fileUploadLib.inc.php, one component).
 *
 * make_path() always creates visible folders and reads its target from
 * globals; this takes the parent explicitly and takes the visibility from
 * the caller, so a folder can be created as a draft.
 *
 * @param int    $courseId
 * @param string $courseCode
 * @param string $parentPath Path of the parent folder, '' for the top level
 * @param string $name       Folder name shown to users
 * @param array  $data       keys: visible (default 0), log
 * @param bool   $createDir  Create the directory on disk (false during a dry run)
 * @param bool   $index      Run search indexing
 * @return int|false The new document id, or false when the directory could not be created
 */
function document_service_create_folder($courseId, $courseCode, $parentPath, $name, array $data, $createDir = true, $index = true) {
    $creator = trim(($_SESSION['givenname'] ?? '') . ' ' . ($_SESSION['surname'] ?? ''));
    $basedir = document_service_basedir($courseCode);
    $name = canonicalize_whitespace($name);
    $path = $parentPath . '/' . safe_filename();
    if ($createDir and !make_dir($basedir . $path)) {
        return false;
    }
    $id = Database::get()->query('INSERT INTO document SET
            course_id = ?d, subsystem = ?d, subsystem_id = NULL, path = ?s,
            filename = ?s, visible = ?d, creator = ?s, date = NOW(), date_modified = NOW(),
            format = \'.dir\'',
        $courseId, MAIN, $path, $name, $data['visible'] ?? 0, $creator)->lastInsertID;
    $id = intval($id);
    Log::record($courseId, MODULE_ID_DOCS, LOG_INSERT, array_merge([
        'id' => $id, 'path' => $path, 'filename' => $name,
    ], $data['log'] ?? []));
    if ($index) {
        $searchEngine = SearchEngineFactory::create();
        $searchEngine->indexResource(ConstantsUtil::REQUEST_STORE, ConstantsUtil::RESOURCE_DOCUMENT, $id);
    }
    return $id;
}

/**
 * @brief Change the metadata of a document (index.php, rename and edit handlers).
 * @param int   $courseId
 * @param int   $id
 * @param array $data  any of: filename, title, comment; plus log
 * @param bool  $index
 * @return bool False when the document is not in the course
 */
function document_service_update($courseId, $id, array $data, $index = true) {
    $document = document_service_get($courseId, $id);
    if (!$document) {
        return false;
    }
    $filename = array_key_exists('filename', $data)
        ? canonicalize_whitespace($data['filename']) : $document->filename;
    $title = array_key_exists('title', $data) ? $data['title'] : $document->title;
    $comment = array_key_exists('comment', $data) ? $data['comment'] : $document->comment;
    Database::get()->query('UPDATE document SET filename = ?s, title = ?s, comment = ?s, date_modified = NOW()
        WHERE id = ?d AND course_id = ?d', $filename, $title, $comment, $id, $courseId);
    Log::record($courseId, MODULE_ID_DOCS, LOG_MODIFY, array_merge([
        'id' => $id, 'path' => $document->path, 'filename' => $document->filename,
        'newfilename' => $filename,
    ], $data['log'] ?? []));
    if ($index) {
        $searchEngine = SearchEngineFactory::create();
        $searchEngine->indexResource(ConstantsUtil::REQUEST_STORE, ConstantsUtil::RESOURCE_DOCUMENT, $id);
    }
    return true;
}

/**
 * @brief Set document visibility and public access (index.php, bulk visible handlers).
 * @param int      $courseId
 * @param int      $id
 * @param int      $visible 0 or 1
 * @param int|null $public  0 or 1, or null to leave unchanged
 * @param bool     $index
 * @return bool False when the document is not in the course
 */
function document_service_set_visibility($courseId, $id, $visible, $public = null, $index = true) {
    if (!document_service_get($courseId, $id)) {
        return false;
    }
    if ($public === null) {
        Database::get()->query('UPDATE document SET visible = ?d WHERE id = ?d AND course_id = ?d',
            $visible ? 1 : 0, $id, $courseId);
    } else {
        Database::get()->query('UPDATE document SET visible = ?d, public = ?d WHERE id = ?d AND course_id = ?d',
            $visible ? 1 : 0, $public ? 1 : 0, $id, $courseId);
    }
    if ($index) {
        $searchEngine = SearchEngineFactory::create();
        $searchEngine->indexResource(ConstantsUtil::REQUEST_STORE, ConstantsUtil::RESOURCE_DOCUMENT, $id);
    }
    return true;
}

/**
 * @brief Replace the bytes of an existing document, keeping its id and path.
 * @param int         $courseId
 * @param string      $courseCode
 * @param object      $document   Row from document_service_get()
 * @param string|null $sourcePath File to copy in, or null for a dry run
 * @param bool        $index
 * @return bool False when the copy failed
 */
function document_service_replace_content($courseId, $courseCode, $document, $sourcePath, $index = true) {
    $basedir = document_service_basedir($courseCode);
    if ($sourcePath !== null and !@copy($sourcePath, $basedir . $document->path)) {
        return false;
    }
    Database::get()->query('UPDATE document SET date_modified = NOW() WHERE id = ?d AND course_id = ?d',
        $document->id, $courseId);
    Log::record($courseId, MODULE_ID_DOCS, LOG_MODIFY, [
        'id' => $document->id, 'path' => $document->path, 'filename' => $document->filename,
        'content_replaced' => true,
    ]);
    if ($index) {
        $searchEngine = SearchEngineFactory::create();
        $searchEngine->indexResource(ConstantsUtil::REQUEST_STORE, ConstantsUtil::RESOURCE_DOCUMENT, $document->id);
    }
    return true;
}
