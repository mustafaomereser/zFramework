<?php

namespace zFramework\Core\Validator\Rules;

use zFramework\Core\Validator\Rule;

class Min extends Rule
{
    public function handle(array $data): bool
    {
        if (!@strlen($data['value'])) return true;
        if ($data['length'] >= $data['equivalent']) return true;
        $this->errors = ['now-val' => $data['length'], 'min-val' => $data['equivalent']];
        return false;
    }
}
