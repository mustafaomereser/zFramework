<?php

namespace zFramework\Core\Validator\Rules;

use zFramework\Core\Validator\Rule;

class Type extends Rule
{
    public function handle(array $data): bool
    {
        if (!@strlen($data['value'])) return true;
        if ($data['equivalent'] == $data['type']) return true;
        $this->errors = ['now-type' => $data['type'], 'must-type' => $data['equivalent']];
        return false;
    }
}
