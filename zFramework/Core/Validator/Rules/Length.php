<?php

namespace zFramework\Core\Validator\Rules;

use zFramework\Core\Validator\Rule;

/**
 * length:11        exactly 11
 * length:8,72      8 to 72
 *
 * Always a count, never a value: characters for text (mb_strlen, so "şçğü" is
 * 4), elements for an array. Where min/max read an all-digit value as a number
 * unless type:string says otherwise, "12345" here is five characters whatever
 * the type - which is what an identity number, a postcode or a password asks.
 */
class Length extends Rule
{
    public function handle(array $data): bool
    {
        if ($this->blank($data['value'])) return true;

        $value = $data['value'];
        if (!is_array($value) && !$this->text($value)) return false;

        $count = is_array($value) ? count($value) : mb_strlen((string) $value, 'UTF-8');

        [$min, $max] = array_pad(array_map('trim', explode(',', (string) $data['equivalent'], 2)), 2, null);
        $max ??= $min;

        if ($count >= (int) $min && $count <= (int) $max) return true;

        $this->errors = ['now-val' => $count, 'length-val' => $min === $max ? $min : "$min-$max"];
        return false;
    }
}
