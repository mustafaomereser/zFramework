<?php

namespace zFramework\Core\Validator\Rules;

use zFramework\Core\Validator\Rule;

class Required extends Rule
{
    public function handle(array $data): bool
    {
        if (in_array('nullable', $data['validateList'])) throw new \Exception('"required" cannot be used in a validation that is "nullable".');

        $value = $data['value'];

        # Asked of the value, not of its measured length. type:integer made length
        # (int) null = 0, and the branch that let a real 0 through - `0 is a valid
        # integer` - could not tell it apart from a field nobody sent, so
        # ['type:integer', 'required'] passed on an empty form.
        if ($value === null || $value === '') return false;
        if (is_array($value)) return count($value) > 0;

        return true;
    }
}
