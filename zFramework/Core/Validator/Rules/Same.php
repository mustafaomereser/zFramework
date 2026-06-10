<?php

namespace zFramework\Core\Validator\Rules;

use zFramework\Core\Facades\Lang;
use zFramework\Core\Validator\Rule;

class Same extends Rule
{
    public function handle(array $data): bool
    {
        if ($data['value'] === @$data['data'][$data['equivalent']]) return true;
        $this->errors = ['attribute-name' => (Lang::get("validator.attributes." . $data['equivalent']) ?? $data['equivalent'])];
        return false;
    }
}
