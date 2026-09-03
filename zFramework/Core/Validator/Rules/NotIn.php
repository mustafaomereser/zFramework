<?php

namespace zFramework\Core\Validator\Rules;

use zFramework\Core\Validator\Rule;

/**
 * not-in:admin,root,system
 */
class NotIn extends Rule
{
    public function handle(array $data): bool
    {
        if ($this->blank($data['value'])) return true;
        if (!$this->text($data['value'])) return false;

        $blocked = array_map('trim', explode(',', (string) $data['equivalent']));

        if (!in_array((string) $data['value'], $blocked, true)) return true;

        $this->errors = ['blocked' => implode(', ', $blocked)];
        return false;
    }
}
