<?php

namespace zFramework\Core\Validator\Rules;

use zFramework\Core\Validator\Rule;

class Type extends Rule
{
    /**
     * Assert the value against the declared type.
     *
     * It compares against the *detected* type, not the declared one. Comparing
     * `type` with `equivalent` meant comparing the declaration with itself,
     * because a declared type overwrites the detected one before the rules run -
     * so `type:integer` accepted "abc" and the rule asserted nothing at all.
     *
     * Every value arriving from a form is a string, so the test is "can this be
     * read as the declared type", not "is this already that PHP type". That is
     * why `type:string` always passes and `type:float` accepts an integer.
     */
    public function handle(array $data): bool
    {
        if (!@strlen($data['value'])) return true;

        $declared = $data['type'];
        $detected = $data['detectedType'] ?? $declared;

        # An unrecognised spelling declares nothing - Validator maps the known
        # ones and passes null for the rest, leaving type === detected.
        if ($declared === $detected) return true;

        $ok = match ($declared) {
            'string'  => true,
            'float'   => $detected === 'integer',
            'boolean' => in_array($data['value'], [0, 1, '0', '1', true, false, 'true', 'false'], true),
            default   => false,
        };

        if ($ok) return true;

        $this->errors = ['now-type' => $detected, 'must-type' => $declared];
        return false;
    }
}
