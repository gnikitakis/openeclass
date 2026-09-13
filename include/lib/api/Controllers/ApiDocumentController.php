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
 * @brief Course documents: the main document area of a course.
 *
 * Draft-first: every file and folder created here is hidden. Making one
 * visible needs documents.publish, as does changing one that students can
 * currently see.
 *
 * A file arrives either as a multipart form field named "file", or, for
 * clients that cannot send multipart, as base64 in a JSON body. Both go
 * through the same checks: the acting teacher's upload whitelist, the
 * content check that compares the bytes with the extension, and the course
 * quota measured on the real size of the file.
 *
 * Documents are addressed by numeric id. Folders are documents whose format
 * is ".dir" and whose name is in filename.
 */
class ApiDocumentController {

    /**
     * GET /courses/{code}/documents?folder_id=
     * @param ApiRequest $request
     * @param ApiContext $context
     * @param array      $params
     * @return array
     */
    public static function index(ApiRequest $request, ApiContext $context, array $params) {
        $parentPath = '';
        if (isset($request->query['folder_id']) and $request->query['folder_id'] !== '') {
            $parentPath = self::loadFolder($context, $request->query['folder_id'])->path;
        }
        $rows = document_service_list($context->courseId, $parentPath);
        return ['data' => array_map(['self', 'json'], $rows)];
    }

    /**
     * GET /courses/{code}/documents/{id}
     * @param ApiRequest $request
     * @param ApiContext $context
     * @param array      $params
     * @return array
     */
    public static function show(ApiRequest $request, ApiContext $context, array $params) {
        return ['data' => self::json(self::load($context, $params['id']))];
    }

    /**
     * GET /courses/{code}/documents/{id}/content: the bytes of the document.
     * @param ApiRequest $request
     * @param ApiContext $context
     * @param array      $params
     * @return array Never returns; the file ends the request
     * @throws ApiException
     */
    public static function content(ApiRequest $request, ApiContext $context, array $params) {
        $document = self::load($context, $params['id']);
        if ($document->format === '.dir') {
            throw new ApiException(ApiErrorCodes::BAD_REQUEST, 'A folder has no content; list it instead');
        }
        if ($document->extra_path) {
            throw new ApiException(ApiErrorCodes::BAD_REQUEST,
                'This document is a link to an external address, not a stored file');
        }
        $path = document_service_basedir($context->courseCode) . $document->path;
        if (!is_file($path)) {
            throw new ApiException(ApiErrorCodes::NOT_FOUND, 'The stored file is missing');
        }
        ApiResponse::sendFile($path, $document->filename);
        return ['data' => null];
    }

    /**
     * POST /courses/{code}/documents: upload a file as a hidden document.
     *
     * Multipart: field "file", plus folder_id, title, comment.
     * JSON: {filename, content_base64, folder_id, title, comment}.
     *
     * @param ApiRequest $request
     * @param ApiContext $context
     * @param array      $params
     * @return array
     * @throws ApiException
     */
    public static function store(ApiRequest $request, ApiContext $context, array $params) {
        [$in, $filename, $sourcePath, $temporary] = self::readUpload($request);
        try {
            $in->rejectUnknown(['filename', 'content_base64', 'folder_id', 'title', 'comment']);
            $folderId = $in->id('folder_id');
            $title = $in->string('title', false, 255) ?? '';
            $comment = $in->string('comment') ?? '';
            $in->check();

            $parentPath = $folderId === null ? '' : self::loadFolder($context, $folderId)->path;
            self::validateUpload($context, $filename, $sourcePath);
            if (document_service_list($context->courseId, $parentPath, canonicalize_whitespace($filename))) {
                throw new ApiException(ApiErrorCodes::VALIDATION_FAILED,
                    'A document with this name already exists in the folder',
                    [['field' => 'filename', 'code' => 'exists']]);
            }
            $size = filesize($sourcePath);
            $stored = null;
            $result = ApiWrite::run($context, function ($commit) use ($context, $parentPath, $sourcePath,
                    $filename, $title, $comment, $size, &$stored) {
                $data = ['title' => $title, 'comment' => $comment, 'visible' => 0,
                    'log' => ApiWrite::logDetails($context)];
                $id = document_service_store($context->courseId, $context->courseCode, $parentPath,
                    $commit ? $sourcePath : null, $filename, $data, $commit);
                if ($id === false) {
                    throw new ApiException(ApiErrorCodes::INTERNAL, 'The file could not be stored');
                }
                $row = document_service_get($context->courseId, $id);
                if ($commit) {
                    $stored = document_service_basedir($context->courseCode) . $row->path;
                }
                return ['data' => self::json($row, $size),
                    'changes' => [['op' => 'insert', 'table' => 'document', 'id' => $id]]];
            }, 201);
            return $result;
        } catch (Throwable $e) {
            // The copy happens inside the transaction but the file system is
            // not transactional: remove the stored copy if the write failed.
            if (isset($stored) and $stored !== null and is_file($stored)) {
                @unlink($stored);
            }
            throw $e;
        } finally {
            if ($temporary and is_file($sourcePath)) {
                @unlink($sourcePath);
            }
        }
    }

    /**
     * POST /courses/{code}/documents/folders  {name, parent_id}
     * @param ApiRequest $request
     * @param ApiContext $context
     * @param array      $params
     * @return array
     */
    public static function createFolder(ApiRequest $request, ApiContext $context, array $params) {
        $in = ApiInput::fromRequest($request);
        $in->rejectUnknown(['name', 'parent_id']);
        $name = $in->string('name', true, 255);
        $parentId = $in->id('parent_id');
        $in->check();
        $parentPath = $parentId === null ? '' : self::loadFolder($context, $parentId)->path;
        if (document_service_list($context->courseId, $parentPath, canonicalize_whitespace($name))) {
            throw new ApiException(ApiErrorCodes::VALIDATION_FAILED,
                'A document with this name already exists in the folder',
                [['field' => 'name', 'code' => 'exists']]);
        }
        return ApiWrite::run($context, function ($commit) use ($context, $parentPath, $name) {
            $id = document_service_create_folder($context->courseId, $context->courseCode, $parentPath, $name,
                ['visible' => 0, 'log' => ApiWrite::logDetails($context)], $commit, $commit);
            if ($id === false) {
                throw new ApiException(ApiErrorCodes::INTERNAL, 'The folder could not be created on disk');
            }
            return ['data' => self::json(document_service_get($context->courseId, $id)),
                'changes' => [['op' => 'insert', 'table' => 'document', 'id' => $id]]];
        }, 201);
    }

    /**
     * PATCH /courses/{code}/documents/{id}  {filename, title, comment}
     * @param ApiRequest $request
     * @param ApiContext $context
     * @param array      $params
     * @return array
     */
    public static function update(ApiRequest $request, ApiContext $context, array $params) {
        $document = self::load($context, $params['id']);
        ApiWrite::requirePublishIfVisible($context, 'documents', $document->visible != 0);
        $in = ApiInput::fromRequest($request);
        $in->rejectUnknown(['filename', 'title', 'comment']);
        $data = [];
        if ($in->has('filename')) {
            $data['filename'] = $in->string('filename', true, 255);
        }
        if ($in->has('title')) {
            $data['title'] = $in->string('title', false, 255) ?? '';
        }
        if ($in->has('comment')) {
            $data['comment'] = $in->string('comment') ?? '';
        }
        if (!$data) {
            $in->error('filename', 'required');
        }
        $in->check();
        if (isset($data['filename']) and $document->format !== '.dir'
                and !document_service_name_allowed($data['filename'])) {
            throw new ApiException(ApiErrorCodes::FILE_TYPE_NOT_ALLOWED,
                'Files of this type may not be stored in this course',
                [['field' => 'filename', 'code' => 'invalid']]);
        }
        $data['log'] = ApiWrite::logDetails($context);
        return ApiWrite::run($context, function ($commit) use ($context, $document, $data) {
            document_service_update($context->courseId, $document->id, $data, $commit);
            return ['data' => self::json(self::load($context, $document->id)),
                'changes' => [['op' => 'update', 'table' => 'document', 'id' => intval($document->id)]]];
        });
    }

    /**
     * POST /courses/{code}/documents/{id}/visibility  {visible, public}
     * @param ApiRequest $request
     * @param ApiContext $context
     * @param array      $params
     * @return array
     */
    public static function visibility(ApiRequest $request, ApiContext $context, array $params) {
        $document = self::load($context, $params['id']);
        $in = ApiInput::fromRequest($request);
        $in->rejectUnknown(['visible', 'public']);
        $visible = $in->bool('visible', true);
        $public = $in->bool('public');
        $in->check();
        return ApiWrite::run($context, function ($commit) use ($context, $document, $visible, $public) {
            document_service_set_visibility($context->courseId, $document->id, $visible ? 1 : 0,
                $public === null ? null : ($public ? 1 : 0), $commit);
            Log::record($context->courseId, MODULE_ID_DOCS, LOG_MODIFY,
                ['id' => $document->id, 'filename' => $document->filename, 'visible' => $visible]
                + ApiWrite::logDetails($context));
            return ['data' => self::json(self::load($context, $document->id)),
                'changes' => [['op' => 'update', 'table' => 'document', 'id' => intval($document->id)]]];
        });
    }

    /**
     * PUT /courses/{code}/documents/{id}/content  {content_base64}
     *
     * Replaces the bytes of an existing document. PHP does not parse
     * multipart bodies on PUT, so the new content is sent as base64.
     *
     * @param ApiRequest $request
     * @param ApiContext $context
     * @param array      $params
     * @return array
     * @throws ApiException
     */
    public static function replaceContent(ApiRequest $request, ApiContext $context, array $params) {
        $document = self::load($context, $params['id']);
        if ($document->format === '.dir') {
            throw new ApiException(ApiErrorCodes::BAD_REQUEST, 'A folder has no content');
        }
        ApiWrite::requirePublishIfVisible($context, 'documents', $document->visible != 0);
        $in = ApiInput::fromRequest($request);
        $in->rejectUnknown(['content_base64']);
        $encoded = $in->string('content_base64', true);
        $in->check();
        $sourcePath = self::decodeToTempFile($encoded);
        try {
            self::validateUpload($context, $document->filename, $sourcePath, intval($document->id));
            $size = filesize($sourcePath);
            return ApiWrite::run($context, function ($commit) use ($context, $document, $sourcePath, $size) {
                if (!document_service_replace_content($context->courseId, $context->courseCode, $document,
                        $commit ? $sourcePath : null, $commit)) {
                    throw new ApiException(ApiErrorCodes::INTERNAL, 'The file could not be stored');
                }
                return ['data' => self::json(self::load($context, $document->id), $size),
                    'changes' => [['op' => 'update', 'table' => 'document', 'id' => intval($document->id)]]];
            });
        } finally {
            if (is_file($sourcePath)) {
                @unlink($sourcePath);
            }
        }
    }

    /**
     * Read the uploaded bytes from a multipart field or from base64 JSON.
     * @param ApiRequest $request
     * @return array{0: ApiInput, 1: string, 2: string, 3: bool} input, file name, local path, whether the path is temporary
     * @throws ApiException
     */
    private static function readUpload(ApiRequest $request) {
        $contentType = strtolower($request->header('Content-Type') ?? '');
        if (str_starts_with($contentType, 'multipart/form-data')) {
            if (!isset($_FILES['file'])) {
                throw new ApiException(ApiErrorCodes::BAD_REQUEST,
                    'A multipart request must carry the file in a field named "file"');
            }
            $file = $_FILES['file'];
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                    throw new ApiException(ApiErrorCodes::QUOTA_EXCEEDED,
                        'The file is larger than the upload limit of this platform');
                }
                throw new ApiException(ApiErrorCodes::BAD_REQUEST, 'The file was not received completely');
            }
            if (!is_uploaded_file($file['tmp_name'])) {
                throw new ApiException(ApiErrorCodes::BAD_REQUEST, 'The file was not received');
            }
            $body = $_POST;
            foreach (['folder_id'] as $numeric) {
                if (isset($body[$numeric]) and is_string($body[$numeric]) and ctype_digit($body[$numeric])) {
                    $body[$numeric] = intval($body[$numeric]);
                }
            }
            $name = (string) ($body['filename'] ?? $file['name']);
            unset($body['filename']);
            return [new ApiInput($body), $name, $file['tmp_name'], false];
        }
        $in = ApiInput::fromRequest($request);
        $filename = $in->string('filename', true, 255);
        $encoded = $in->string('content_base64', true);
        $in->check();
        return [$in, $filename, self::decodeToTempFile($encoded), true];
    }

    /**
     * @param string $encoded
     * @return string Path of a temporary file holding the decoded bytes
     * @throws ApiException
     */
    private static function decodeToTempFile($encoded) {
        $decoded = base64_decode(preg_replace('/\s+/', '', $encoded), true);
        if ($decoded === false) {
            throw new ApiException(ApiErrorCodes::VALIDATION_FAILED, 'content_base64 is not valid base64',
                [['field' => 'content_base64', 'code' => 'invalid']]);
        }
        if (strlen($decoded) > DOCUMENT_SERVICE_MAX_BASE64_BYTES) {
            throw new ApiException(ApiErrorCodes::QUOTA_EXCEEDED,
                'A file sent as base64 may not exceed ' . DOCUMENT_SERVICE_MAX_BASE64_BYTES . ' bytes; use a multipart upload');
        }
        $path = tempnam(sys_get_temp_dir(), 'eclass_api_');
        if ($path === false or file_put_contents($path, $decoded) === false) {
            throw new ApiException(ApiErrorCodes::INTERNAL, 'The uploaded content could not be buffered');
        }
        return $path;
    }

    /**
     * Name, content and quota checks, in that order.
     * @param ApiContext $context
     * @param string     $filename
     * @param string     $sourcePath
     * @param int|null   $replacingId Document whose bytes are being replaced, excluded from the quota
     * @throws ApiException
     */
    private static function validateUpload(ApiContext $context, $filename, $sourcePath, $replacingId = null) {
        if (trim($filename) === '' or str_contains($filename, '/') or str_contains($filename, '\\')) {
            throw new ApiException(ApiErrorCodes::VALIDATION_FAILED, 'The file name is not usable',
                [['field' => 'filename', 'code' => 'invalid']]);
        }
        if (!document_service_name_allowed($filename)) {
            throw new ApiException(ApiErrorCodes::FILE_TYPE_NOT_ALLOWED,
                'Files of this type may not be uploaded to this course');
        }
        $problem = document_service_content_check($sourcePath, $filename);
        if ($problem === 'file_type_not_allowed') {
            throw new ApiException(ApiErrorCodes::FILE_TYPE_NOT_ALLOWED,
                'The content of this file is a program or a script and is never accepted');
        }
        if ($problem === 'mime_mismatch') {
            throw new ApiException(ApiErrorCodes::MIME_MISMATCH,
                'The content of this file does not match the type its name claims');
        }
        $basedir = document_service_basedir($context->courseCode);
        $quota = document_service_quota($context->courseId);
        $used = document_service_disk_used($basedir);
        if ($replacingId !== null) {
            $existing = document_service_get($context->courseId, $replacingId);
            $existingPath = $existing ? $basedir . $existing->path : null;
            if ($existingPath and is_file($existingPath)) {
                $used -= filesize($existingPath);
            }
        }
        if ($quota > 0 and $used + filesize($sourcePath) > $quota) {
            throw new ApiException(ApiErrorCodes::QUOTA_EXCEEDED,
                'The course document quota would be exceeded by this file');
        }
    }

    /**
     * @param ApiContext $context
     * @param int|string $id
     * @return object document row
     * @throws ApiException not_found
     */
    private static function load(ApiContext $context, $id) {
        $document = document_service_get($context->courseId, intval($id));
        if (!$document) {
            throw new ApiException(ApiErrorCodes::NOT_FOUND,
                "Document $id does not exist in course '{$context->courseCode}'");
        }
        return $document;
    }

    /**
     * @param ApiContext $context
     * @param int|string $id
     * @return object document row of a folder
     * @throws ApiException not_found
     */
    private static function loadFolder(ApiContext $context, $id) {
        $folder = self::load($context, $id);
        if ($folder->format !== '.dir') {
            throw new ApiException(ApiErrorCodes::NOT_FOUND, "Document $id is not a folder");
        }
        return $folder;
    }

    /**
     * @param object   $d    document row
     * @param int|null $size Size to report when the file is not on disk yet (dry run)
     * @return array
     */
    private static function json($d, $size = null) {
        global $webDir;
        $isFolder = $d->format === '.dir';
        if ($size === null and !$isFolder and !$d->extra_path) {
            $path = $webDir . '/courses/' . self::courseCodeOf($d) . '/document' . $d->path;
            $size = is_file($path) ? filesize($path) : null;
        }
        $parent = my_dirname($d->path);
        $parentRow = $parent === '' || $parent === '/' || $parent === '.'
            ? null
            : Database::get()->querySingle('SELECT id FROM document
                WHERE course_id = ?d AND subsystem = ?d AND path = ?s', $d->course_id, MAIN, $parent);
        return [
            'id' => intval($d->id),
            'kind' => $isFolder ? 'folder' : 'file',
            'filename' => $d->filename,
            'title' => (string) $d->title,
            'comment' => (string) $d->comment,
            'format' => $d->format,
            'size_bytes' => $isFolder ? null : $size,
            'visible' => $d->visible != 0,
            'public' => $d->public != 0,
            'folder_id' => $parentRow ? intval($parentRow->id) : null,
            'external_url' => $d->extra_path ?: null,
            'date' => $d->date,
            'date_modified' => $d->date_modified,
        ];
    }

    /**
     * @param object $d document row
     * @return string course code of the document's course
     */
    private static function courseCodeOf($d) {
        global $course_code, $course_id;
        if (isset($course_id) and intval($course_id) === intval($d->course_id)) {
            return $course_code;
        }
        $c = Database::get()->querySingle('SELECT code FROM course WHERE id = ?d', $d->course_id);
        return $c ? $c->code : '';
    }
}
