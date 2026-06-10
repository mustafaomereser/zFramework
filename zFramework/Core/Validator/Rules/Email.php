<?php

namespace zFramework\Core\Validator\Rules;

use zFramework\Core\Validator\Rule;

class Email extends Rule
{
    public function handle(array $data): bool
    {
        if (!@strlen($data['value'])) return true;
        if (filter_var($data['value'], FILTER_VALIDATE_EMAIL)) return true;
        return false;
    }
}
