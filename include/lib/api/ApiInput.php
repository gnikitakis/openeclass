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
 * @brief Typed access to a JSON request body with collected field errors.
 *
 * Each accessor records a field error instead of throwing, so a client gets
 * every problem of a request at once. Call check() before using the values.
 * Field error codes: required, type, too_long, invalid, unknown.
 */
class ApiInput {

    /** @var array Decoded body */
    private $body;

    /** @var array<int, array{field: string, code: string}> */
    private $errors = [];

    /**
     * @param array $body
     */
    public function __construct(array $body) {
        $this->body = $body;
    }

    /**
     * @param ApiRequest $request
     * @return ApiInput
     * @throws ApiException when the body is not a JSON object
     */
    public static function fromRequest(ApiRequest $request) {
        return new ApiInput($request->jsonBody());
    }

    /**
     * @param string $field
     * @return bool Whether the field is present (null counts as present)
     */
    public function has($field) {
        return array_key_exists($field, $this->body);
    }

    /**
     * @param string[] $fields
     * @return bool Whether at least one of the fields is present
     */
    public function hasAny(array $fields) {
        foreach ($fields as $field) {
            if ($this->has($field)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Plain string. Leading and trailing whitespace is trimmed.
     * @param string   $field
     * @param bool     $required  Absent, null or empty is an error
     * @param int|null $maxLength
     * @return string|null Null when absent or null
     */
    public function string($field, $required = false, $maxLength = null) {
        if (!$this->has($field) or $this->body[$field] === null) {
            if ($required) {
                $this->errors[] = ['field' => $field, 'code' => 'required'];
            }
            return null;
        }
        $value = $this->body[$field];
        if (!is_string($value)) {
            $this->errors[] = ['field' => $field, 'code' => 'type'];
            return null;
        }
        $value = trim($value);
        if ($required and $value === '') {
            $this->errors[] = ['field' => $field, 'code' => 'required'];
            return null;
        }
        if ($maxLength !== null and mb_strlen($value) > $maxLength) {
            $this->errors[] = ['field' => $field, 'code' => 'too_long'];
            return null;
        }
        return $value;
    }

    /**
     * HTML fragment, cleaned with the platform's purifier (the same filter
     * the editor output goes through in the interface).
     * @param string $field
     * @param bool   $required
     * @return string|null
     */
    public function html($field, $required = false) {
        $value = $this->string($field, $required);
        return $value === null ? null : purify($value);
    }

    /**
     * Calendar date, accepted as YYYY-MM-DD.
     * @param string $field
     * @return string|null YYYY-MM-DD, or null when absent or null
     */
    public function date($field) {
        $value = $this->string($field);
        if ($value === null or $value === '') {
            return null;
        }
        $d = DateTime::createFromFormat('!Y-m-d', $value);
        if (!$d or $d->format('Y-m-d') !== $value) {
            $this->errors[] = ['field' => $field, 'code' => 'invalid'];
            return null;
        }
        return $value;
    }

    /**
     * Date and time, accepted as "YYYY-MM-DD HH:MM", "YYYY-MM-DD HH:MM:SS" or
     * ISO 8601 with a T separator; interpreted in the platform's time zone.
     * @param string $field
     * @return string|null "Y-m-d H:i:s", or null when absent or null
     */
    public function dateTime($field) {
        $value = $this->string($field);
        if ($value === null or $value === '') {
            return null;
        }
        foreach (['Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d\TH:i:s', 'Y-m-d\TH:i'] as $format) {
            $d = DateTime::createFromFormat('!' . $format, $value);
            if ($d and $d->format($format) === $value) {
                return $d->format('Y-m-d H:i:s');
            }
        }
        $this->errors[] = ['field' => $field, 'code' => 'invalid'];
        return null;
    }

    /**
     * @param string $field
     * @param bool   $required
     * @return bool|null
     */
    public function bool($field, $required = false) {
        if (!$this->has($field) or $this->body[$field] === null) {
            if ($required) {
                $this->errors[] = ['field' => $field, 'code' => 'required'];
            }
            return null;
        }
        if (!is_bool($this->body[$field])) {
            $this->errors[] = ['field' => $field, 'code' => 'type'];
            return null;
        }
        return $this->body[$field];
    }

    /**
     * Positive integer identifier.
     * @param string $field
     * @param bool   $required
     * @return int|null
     */
    public function id($field, $required = false) {
        if (!$this->has($field) or $this->body[$field] === null) {
            if ($required) {
                $this->errors[] = ['field' => $field, 'code' => 'required'];
            }
            return null;
        }
        $value = $this->body[$field];
        if (!is_int($value) or $value < 1) {
            $this->errors[] = ['field' => $field, 'code' => 'type'];
            return null;
        }
        return $value;
    }

    /**
     * Non-empty list of positive integer identifiers.
     * @param string $field
     * @return int[]|null
     */
    public function idList($field) {
        if (!$this->has($field) or !is_array($this->body[$field]) or !$this->body[$field]
                or array_keys($this->body[$field]) !== range(0, count($this->body[$field]) - 1)) {
            $this->errors[] = ['field' => $field, 'code' => 'required'];
            return null;
        }
        foreach ($this->body[$field] as $value) {
            if (!is_int($value) or $value < 1) {
                $this->errors[] = ['field' => $field, 'code' => 'type'];
                return null;
            }
        }
        return $this->body[$field];
    }

    /**
     * One of a fixed set of strings.
     * @param string   $field
     * @param string[] $allowed
     * @param bool     $required
     * @return string|null
     */
    public function oneOf($field, array $allowed, $required = false) {
        $value = $this->string($field, $required);
        if ($value !== null and !in_array($value, $allowed, true)) {
            $this->errors[] = ['field' => $field, 'code' => 'invalid'];
            return null;
        }
        return $value;
    }

    /**
     * Record that fields other than the given ones are not understood.
     * @param string[] $known
     */
    public function rejectUnknown(array $known) {
        foreach (array_keys($this->body) as $field) {
            if (!in_array($field, $known, true)) {
                $this->errors[] = ['field' => (string) $field, 'code' => 'unknown'];
            }
        }
    }

    /**
     * Record a field error found by the caller.
     * @param string $field
     * @param string $code
     */
    public function error($field, $code) {
        $this->errors[] = ['field' => $field, 'code' => $code];
    }

    /**
     * Fail the request if any field error was recorded.
     * @throws ApiException validation_failed with field_errors
     */
    public function check() {
        if ($this->errors) {
            throw new ApiException(ApiErrorCodes::VALIDATION_FAILED, 'The request body is not valid', $this->errors);
        }
    }
}
