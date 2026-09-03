<?php

namespace zFramework\Core\Validator\Rules;

use zFramework\Core\Validator\Rule;

class Exists extends Rule
{
    public function handle(array $data): bool
    {
        # Nothing to look up. Without this the rule defeated nullable on every
        # optional foreign key: the blank went to the database as `col = ''`, matched
        # no row, and the field could not be left empty however it was declared.
        if ($data['value'] === null || $data['value'] === '') return true;

        $exists = (new $data['equivalent'])->where($data['parameters']['key'] ?? $data['key'], $data['value']);
        if ($ex = @$data['parameters']['ex']) $exists->where($exists->getPrimary() ?? 'id', '!=', $ex);
        if ($exists->count()) return true;
        return false;
    }
}
