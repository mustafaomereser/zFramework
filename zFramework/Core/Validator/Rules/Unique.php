<?php

namespace zFramework\Core\Validator\Rules;

use zFramework\Core\Validator\Rule;

class Unique extends Rule
{
    public function handle(array $data): bool
    {
        # Nothing to check against on a blank - `['nullable', 'unique:User']` on an
        # optional field used to reach where() with null and throw. An array cannot
        # be unique in a column; it fails rather than passing through a bad bind.
        if ($this->blank($data['value'])) return true;
        if (!$this->text($data['value'])) return false;

        $unique = (new $data['equivalent'])->where($data['parameters']['key'] ?? $data['key'], $data['value']);
        # By the model's key, not the literal `id` - a uuid/char key names it differently.
        if ($ex = @$data['parameters']['ex']) $unique->where($unique->getPrimary() ?? 'id', '!=', $ex);
        if ($unique->count()) return false;
        return true;
    }
}
