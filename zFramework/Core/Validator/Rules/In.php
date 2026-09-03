<?php

namespace zFramework\Core\Validator\Rules;

use zFramework\Core\Validator\Rule;

/**
 * in:draft,published,archived
 *
 * Compared as strings, because everything off a form is one.
 */
class In extends Rule
{
    public function handle(array $data): bool
    {
        if ($this->blank($data['value'])) return true;
        if (!$this->text($data['value'])) return false;

        $allowed = array_map('trim', explode(',', (string) $data['equivalent']));

        if (in_array((string) $data['value'], $allowed, true)) return true;

        $this->errors = ['allowed' => implode(', ', $allowed)];
        return false;
    }
}
