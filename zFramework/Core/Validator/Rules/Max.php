<?php

namespace zFramework\Core\Validator\Rules;

use zFramework\Core\Validator\Rule;

class Max extends Rule
{
    public function handle(array $data): bool
    {
        if ($this->blank($data['value'])) return true;
        if ($data['equivalent'] >= $data['length']) return true;
        $this->errors = ['now-val' => $data['length'], 'max-val' => $data['equivalent']];
        return false;
    }
}
