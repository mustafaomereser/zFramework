<?php

namespace zFramework\Core\Validator\Rules;

use zFramework\Core\Validator\Rule;

class Nullable extends Rule
{
    public function handle(array $data): bool
    {
        if (in_array('required', $data['validateList'])) throw new \Exception('"nullable" cannot be used in a validation that is "required".');
        return true;
    }
}
