<?php

namespace zFramework\Core\Validator\Rules;

use zFramework\Core\Validator\Rule;

class Max extends Rule
{
    public function handle(array $data): bool
    {
        if (!@strlen($data['value'])) return true;
        if ($data['equivalent'] >= $data['length']) return true;
        $this->errors = ['now-val' => $data['length'], 'max-val' => $data['equivalent']];
        return false;
    }
}
