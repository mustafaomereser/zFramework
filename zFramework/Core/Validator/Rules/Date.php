<?php

namespace zFramework\Core\Validator\Rules;

use zFramework\Core\Validator\Rule;

/**
 * date            any string strtotime() understands
 * date:Y-m-d      that exact format, and a real date in it
 *
 * The format check re-formats what it parsed and compares: without that,
 * '2026-02-31' parses happily and becomes the 3rd of March.
 */
class Date extends Rule
{
    public function handle(array $data): bool
    {
        if ($this->blank($data['value'])) return true;
        if (!$this->text($data['value'])) return false;

        $value  = (string) $data['value'];
        $format = $data['equivalent'] ?? null;

        if (!$format) return strtotime($value) !== false;

        $parsed = \DateTime::createFromFormat((string) $format, $value);

        return $parsed && $parsed->format((string) $format) === $value;
    }
}
