<?php

namespace zFramework\Core\Validator\Rules;

use zFramework\Core\Validator\Rule;

/**
 * confirmed             expects <field>_confirmation
 * confirmed:re-password expects that field instead
 *
 * `same` does the comparison the other way round - you put it on the second
 * field and name the first. This one goes on the field itself, which is where
 * the error belongs.
 */
class Confirmed extends Rule
{
    public function handle(array $data): bool
    {
        $other = $data['equivalent'] ?: $data['key'] . '_confirmation';

        $mine   = $data['value'];
        $theirs = $data['data'][$other] ?? null;

        # Two arrays both cast to "Array" and matched; compared as arrays now.
        if ($this->text($mine) && $this->text($theirs) ? (string) $mine === (string) $theirs : $mine === $theirs) return true;

        $this->errors = ['other' => $other];
        return false;
    }
}
