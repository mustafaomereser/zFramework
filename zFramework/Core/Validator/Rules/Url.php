<?php

namespace zFramework\Core\Validator\Rules;

use zFramework\Core\Validator\Rule;

/**
 * url  -  and only http/https, because FILTER_VALIDATE_URL alone accepts
 * javascript: and data:, which is how a "website" field becomes an XSS hole.
 */
class Url extends Rule
{
    public function handle(array $data): bool
    {
        if ($this->blank($data['value'])) return true;
        if (!$this->text($data['value'])) return false;
        if (!filter_var($data['value'], FILTER_VALIDATE_URL)) return false;

        return in_array(strtolower((string) parse_url((string) $data['value'], PHP_URL_SCHEME)), ['http', 'https'], true);
    }
}
