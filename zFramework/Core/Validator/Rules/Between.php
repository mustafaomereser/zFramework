<?php

namespace zFramework\Core\Validator\Rules;

use zFramework\Core\Validator\Rule;

/**
 * between:18,65
 *
 * Reads the same length min and max do: the value itself for a number, the
 * character count for a string, the element count for an array. type: decides
 * which - see the Type rule.
 */
class Between extends Rule
{
    public function handle(array $data): bool
    {
        if (!@strlen($data['value'])) return true;

        [$min, $max] = array_pad(array_map('trim', explode(',', (string) $data['equivalent'])), 2, null);

        $length = $data['length'];

        if ($length >= (float) $min && $length <= (float) $max) return true;

        $this->errors = ['now-val' => $length, 'min-val' => $min, 'max-val' => $max];
        return false;
    }
}
