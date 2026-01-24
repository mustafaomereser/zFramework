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
    public static function validate(?array $data = null, array $validate = [], array $attributeNames = [], ?\Closure $callback = null)
    {
        if (!$data) $data = $_REQUEST;

        $errors  = [];
        $statics = [];

        foreach ($validate as $key => $validateArray) {
            $value = @$data[$key];

            $length = -1;
            $type   = null;
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
                if (str_contains($validate, ':')) {
                    preg_match_all('/([\w$.()]+):(?:"([^"]*)"|([^\s;]+))/', $validate, $m, PREG_SET_ORDER);
                    $out = [];
                    foreach ($m as $match) $out[$match[1]] = isset($match[2]) && $match[2] !== '' ? $match[2] : $match[3];
                    $case       = array_key_first($out);
                    $equivalent = $out[$case];
                    unset($out[$case]);
                    foreach ($out as $param_key => $param) $parameters[$param_key] = $param;
                } else {
                    $case = $validate;
                }

                if (self::{$case}(compact('value', 'equivalent', 'length', 'type', 'key'), $parameters, in_array('nullable', $validateArray), $validateArray, $data)) {
                    $statics[$key] = $value;
                } else {
                    $errors[$key][$case] = (Lang::get("validator.attributes.$key") ?? ($attributeNames[$key] ?? $key)) . " " . Lang::get("validator.errors.$case", self::$errors);
                    Alerts::danger($errors[$key][$case]);
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
        if (!strlen($data['value'])) return true;
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
        $exists = (new $data['equivalent'])->whereRaw(($parameters['key'] ?? $data['key']) . " = :value", ['value' => $data['value']]);
        if ($ex = @$parameters['ex']) $exists->where('id', '!=', $ex);
        if ($exists->count()) return true;
        return false;
    }

    public static function unique($data, $parameters)
    {
        $unique = (new $data['equivalent'])->whereRaw(($parameters['key'] ?? $data['key']) . " = :value", ['value' => $data['value']]);
        if ($ex = @$parameters['ex']) $unique->where('id', '!=', $ex);
        if ($unique->count()) return false;
        return true;
    }
}
