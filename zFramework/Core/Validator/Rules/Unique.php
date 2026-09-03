<?php

namespace zFramework\Core\Validator\Rules;

use zFramework\Core\Validator\Rule;

class Unique extends Rule
{
    public function handle(array $data): bool
    {
        $unique = (new $data['equivalent'])->where($data['parameters']['key'] ?? $data['key'], $data['value']);
        # By the model's key, not the literal `id` - a uuid/char key names it differently.
        if ($ex = @$data['parameters']['ex']) $unique->where($unique->getPrimary() ?? 'id', '!=', $ex);
        if ($unique->count()) return false;
        return true;
    }
}
