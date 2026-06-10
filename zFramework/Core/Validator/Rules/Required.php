<?php

namespace zFramework\Core\Validator\Rules;

use zFramework\Core\Validator\Rule;

class Required extends Rule
{
    public function handle(array $data): bool
    {
        if (in_array('nullable', $data['validateList'])) throw new \Exception('"required" cannot be used in a validation that is "nullable".');
        if ($data['type'] === null) return false;
        if ($data['length'] > 0) return true;
        if ($data['type'] === 'integer' || $data['type'] === 'float') return true; // 0 / 0.0 geçerli
        return false;
    }
}
