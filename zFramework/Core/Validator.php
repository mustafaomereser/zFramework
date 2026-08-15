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
     * Spellings accepted by `type:x`, mapped to the canonical name.
     *
     * Without this, `type:int` fell through to the string branch and min/max
     * measured its length instead of its value - the opposite of what the rule
     * was asking for. Anything not listed here declares nothing: length is
     * measured as a string and the type rule does not assert.
     */
    private const TYPE_ALIASES = [
        'int'     => 'integer',
        'integer' => 'integer',
        'float'   => 'float',
        'double'  => 'float',
        'real'    => 'float',
        'str'     => 'string',
        'string'  => 'string',
        'bool'    => 'boolean',
        'boolean' => 'boolean',
        'array'   => 'array',
        'object'  => 'object',
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
            $declaredType = $declaredType !== null ? (self::TYPE_ALIASES[strtolower($declaredType)] ?? null) : null;

            [$type, $length, $detectedType] = self::resolveTypeAndLength($value, $declaredType);

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

                $ruleData = compact('value', 'equivalent', 'length', 'type', 'detectedType', 'key', 'parameters', 'validateList', 'data') + [
                    'required' => in_array('required', $validateList),
                    'nullable' => in_array('nullable', $validateList),
                ];

                $rule = self::resolveRule($case);

                if (!$rule->handle($ruleData)) {
                    $errors[$key][$case] = (Lang::get("validator.attributes.$key") ?? ($attributeNames[$key] ?? $key)) . " " . Lang::get("validator.errors.$case", $rule->errors);
                    Alerts::danger($errors[$key][$case]);
                    unset($data[$key]);
                } else $statics[$key] = $value;
            }
        }

        if (count($errors)) {
            if (!$callback) {
                if (Http::isAjax()) abort(400, Response::json($errors));
                # The alert was already raised where the rule failed, for every path.
                # Raising it again here showed the visitor each message twice.
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
        if (is_array($value))  return ['array',  count($value), 'array'];
        if (is_object($value)) return ['object', count((array) $value), 'object'];

        $detected = match (true) {
            is_int($value)                              => 'integer',
            is_float($value)                            => 'float',
            is_bool($value)                             => 'boolean',
            # === 1: match compares strictly, and preg_match returns an int - so
            # this arm never fired and every numeric string was detected as float.
            preg_match('/^-?\d+$/', (string) $value) === 1 => 'integer',
            is_numeric($value)                          => 'float',
            default                                     => 'string',
        };

        # What min/max measure. A declared type wins: `type:string` on "150" asks
        # for three characters, `type:integer` asks for the number 150. Detection
        # only decides when nothing was declared - every value off a form is a
        # string, so "150" would otherwise always be read as a number.
        $type = $declared ?? $detected;

        $length = match ($type) {
            'integer' => (int) $value,
            'float'   => (float) $value,
            default   => is_string($value) ? strlen($value) : 0,
        };

        return [$type, $length, $detected];
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
