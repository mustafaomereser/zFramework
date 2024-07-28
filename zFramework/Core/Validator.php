<?php

namespace zFramework\Core;

use zFramework\Core\Facades\Alerts;
use zFramework\Core\Facades\DB;
use zFramework\Core\Facades\Lang;
use zFramework\Core\Facades\Response;
use zFramework\Core\Helpers\Http;

class Validator
{

    static $errors = [];
    /**
     * Validate a array
     * @param array $data
     * @param array $validate
     * @param array $attributeNames
     * @param \Closure $callback
     * @return string|array
     */
    public static function validate(array $data = null, array $validate = [], array $attributeNames = [], \Closure $callback = null)
    {
        if (!$data) $data = $_REQUEST;

        $errors  = [];
        $statics = [];

        foreach ($validate as $key => $validateArray) {
            $value = @$data[$key];

            $length = -1;
            if (is_numeric($value) || $value == "0") {
                $type   = 'integer';
                $length = strlen($value);
            } elseif (is_string($value)) {
                $type   = 'string';
                $length = strlen($value);
            } elseif (is_array($value)) {
                $type   = 'array';
                $length = count($value);
            } elseif (is_object($value)) {
                $type   = 'object';
                $length = count((array) $value);
            }

            $equivalent = null;
            $parameters = [];
            foreach ($validateArray as $validate) {
                $e    = explode(':', $validate);
                $case = $e[0];

                if (isset($e[1])) {
                    $_ = explode(' ', $e[1]);
                    $equivalent = $_[0];

                    if (!empty($_[1])) {
                        foreach (@explode(',', $_[1]) as $parameter) {
                            $parameter = explode('=', trim($parameter));
                            if (isset($parameter[1])) $parameters[$parameter[0]] = $parameter[1];
                            else $parameters[] = $parameter[0];
                        }
                    }
                }

                if (self::{$case}(compact('value', 'equivalent', 'length', 'type', 'key'), $parameters, in_array('nullable', $validateArray), $validateArray, $data)) {
                    $statics[$key] = $value;
                } else {
                    $errors[$key][] = (Lang::get("validator.attributes.$key") ?? ($attributeNames[$key] ?? $key)) . " " . Lang::get("validator.errors.$case", self::$errors);
                    unset($data[$key]);
                }
            }
        }

        self::$errors = [];
        if (count($errors)) {
            if (!$callback) {
                if (Http::isAjax()) abort(400, Response::json($errors));
                foreach ($errors as $key => $error_list) foreach ($error_list as $error) Alerts::danger($error);
                back();
            } else {
                $callback($errors, $statics);
            }
        }

        return $statics;
    }

    public static function type($data, $parameters, $nullable)
    {
        if ($nullable && !strlen($data['value'])) return true;
        if ($data['equivalent'] == $data['type']) return true;
        self::$errors = ['now-type' => $data['type'], 'must-type' => $data['equivalent']];
        return false;
    }

    public static function email($data, $parameters, $nullable)
    {
        if ($nullable && !strlen($data['value'])) return true;
        if (filter_var($data['value'], FILTER_VALIDATE_EMAIL)) return true;
        return false;
    }

    public static function required($data, $parameters, $nullable, $validate)
    {
        if (in_array('nullable', $validate)) return throw new \Exception('“required” cannot be used in a validation that is ”nullable”.');
        if ($data['length'] > 0) return true;
        return false;
    }

    public static function nullable($data, $parameters, $nullable, $validate)
    {
        if (in_array('required', $validate)) return throw new \Exception('“nullable” cannot be used in a validation that is ”required”.');
        return true;
    }

    public static function max($data)
    {
        if ($data['equivalent'] >= $data['length']) return true;
        self::$errors = ['now-val' => $data['length'], 'max-val' => $data['equivalent']];
        return false;
    }

    public static function min($data)
    {
        if ($data['length'] >= $data['equivalent']) return true;
        self::$errors = ['now-val' => $data['length'], 'min-val' => $data['equivalent']];
        return false;
    }

    public static function same($data, $parameters, $nullable, $validate, $validate_data)
    {
        if ($data['value'] === @$validate_data[$data['equivalent']]) return true;
        self::$errors = ['attribute-name' => (Lang::get("validator.attributes." . $data['equivalent']) ?? $data['equivalent'])];
        return false;
    }

    public static function exists($data, $parameters)
    {
        $exists = (new DB(@$parameters['db']))->table($data['equivalent'])->where(($parameters['key'] ?? $data['key']), $data['value']);
        if ($ex = @$parameters['ex']) $exists->where('id', '!=', $ex);
        if ($exists->count()) return true;
        return false;
    }

    public static function unique($data, $parameters)
    {
        if (empty($data['value'])) return true;
        $column = $parameters['key'] ?? $data['key'];
        if (!(new DB(@$parameters['db']))->table($data['equivalent'])->where($column, $data['value'])->count()) return true;
        return false;
    }
}
