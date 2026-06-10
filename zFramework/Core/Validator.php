<?php

namespace zFramework\Core;

use zFramework\Core\Facades\Alerts;
use zFramework\Core\Facades\Lang;
use zFramework\Core\Facades\Response;
use zFramework\Core\Helpers\Http;
use zFramework\Core\Validator\Rule;

class Validator
{
    private static array $ruleMap = [
        'required' => Validator\Rules\Required::class,
        'nullable' => Validator\Rules\Nullable::class,
        'max'      => Validator\Rules\Max::class,
        'min'      => Validator\Rules\Min::class,
        'type'     => Validator\Rules\Type::class,
        'email'    => Validator\Rules\Email::class,
        'same'     => Validator\Rules\Same::class,
        'exists'   => Validator\Rules\Exists::class,
        'unique'   => Validator\Rules\Unique::class,
    ];

    /**
     * Validate an array
     * @param array $data
     * @param array $validate
     * @param array $attributeNames
     * @param \Closure $callback
     * @return array
     */
    public static function validate(?array $data = null, array $validate = [], array $attributeNames = [], ?\Closure $callback = null): array
    {
        if (!$data) $data = $_REQUEST;

        $errors  = [];
        $statics = [];

        foreach ($validate as $key => $validateList) {
            $value = $data[$key] ?? null;

            $typeRule     = preg_grep('/^type:/', $validateList);
            $declaredType = $typeRule ? substr(reset($typeRule), 5) : null;

            [$type, $length] = self::resolveTypeAndLength($value, $declaredType);

            $equivalent = null;
            $parameters = [];

            foreach ($validateList as $ruleString) {
                if (str_contains($ruleString, ':')) {
                    preg_match_all('/([\w$.()]+):(?:"([^"]*)"|([^\s;]+))/', $ruleString, $m, PREG_SET_ORDER);
                    $out = [];
                    foreach ($m as $match) $out[$match[1]] = isset($match[2]) && $match[2] !== '' ? $match[2] : $match[3];
                    $case       = array_key_first($out);
                    $equivalent = $out[$case];
                    unset($out[$case]);
                    foreach ($out as $param_key => $param) $parameters[$param_key] = $param;
                } else $case = $ruleString;

                $ruleData = compact('value', 'equivalent', 'length', 'type', 'key', 'parameters', 'validateList', 'data') + [
                    'required' => in_array('required', $validateList),
                    'nullable' => in_array('nullable', $validateList),
                ];

                $rule = self::resolveRule($case);

                if ($rule->handle($ruleData)) {
                    $statics[$key] = $value;
                } else {
                    $errors[$key][$case] = (Lang::get("validator.attributes.$key") ?? ($attributeNames[$key] ?? $key)) . " " . Lang::get("validator.errors.$case", $rule->errors);
                    Alerts::danger($errors[$key][$case]);
                    unset($data[$key]);
                }
            }
        }

        if (count($errors)) {
            if (!$callback) {
                if (Http::isAjax()) abort(400, Response::json($errors));
                foreach ($errors as $error_list) foreach ($error_list as $error) Alerts::danger($error);
                back();
            } else $callback($errors, $statics);
        }

        return $statics;
    }

    /**
     * Detects the type and comparable length of a value.
     * If a type is explicitly declared in the rule list it takes priority over auto-detection.
     * @param mixed $value The field value from the input data
     * @param string|null $declared Explicit type declared via type:xxx rule, or null for auto-detection
     * @return array{0: string|null, 1: int|float} [$type, $length]
     */
    private static function resolveTypeAndLength(mixed $value, ?string $declared): array
    {
        if (is_array($value))  return ['array',  count($value)];
        if (is_object($value)) return ['object', count((array) $value)];

        $type = $declared ?? match(true) {
            is_int($value)                              => 'integer',
            is_float($value)                            => 'float',
            preg_match('/^-?\d+$/', (string) $value)    => 'integer',
            is_numeric($value)                          => 'float',
            default                                     => 'string',
        };

        $length = match($type) {
            'integer' => (int) $value,
            'float'   => (float) $value,
            default   => is_string($value) ? strlen($value) : 0,
        };

        return [$type, $length];
    }

    /**
     * Resolves a rule name to its Rule instance from the rule map.
     * @param string $case Rule name (e.g. "required", "max", "exists")
     * @return Rule
     * @throws \Exception If the rule is not registered
     */
    private static function resolveRule(string $case): Rule
    {
        $class = self::$ruleMap[$case] ?? null;
        if (!$class) throw new \Exception("Unknown validation rule: \"$case\"");
        return new $class();
    }
}
