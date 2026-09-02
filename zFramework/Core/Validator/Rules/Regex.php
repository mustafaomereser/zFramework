<?php

namespace zFramework\Core\Validator\Rules;

use zFramework\Core\Validator\Rule;

/**
 * regex:"^[a-z0-9_]+$"
 *
 * Quote the pattern: unquoted, the rule parser stops at the first space or
 * semicolon. Delimiters are added here, so write the pattern without them -
 * a slash in the pattern then needs no escaping.
 */
class Regex extends Rule
{
    public function handle(array $data): bool
    {
        if (!@strlen($data['value'])) return true;

        $pattern = (string) $data['equivalent'];
        $result  = @preg_match('/' . str_replace('/', '\/', $pattern) . '/u', (string) $data['value']);

        if ($result === false) {
            # /u makes preg_match fail on a value that is not valid UTF-8, which is
            # the visitor's doing rather than the pattern's. Throwing here answered
            # 500 and pointed the developer at a pattern that was never wrong.
            # Bytes that are not text are not in the expected format either.
            if (preg_last_error() === PREG_BAD_UTF8_ERROR) return false;

            # An invalid pattern is a developer error, and passing silently would
            # hide it behind a form that accepts anything.
            throw new \Exception("Validator: regex `$pattern` is not a valid pattern.");
        }

        return (bool) $result;
    }
}
