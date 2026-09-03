<?php

namespace zFramework\Core\Validator\Rules;

use zFramework\Core\Validator\Rule;

class Unique extends Rule
{
    public function handle(array $data): bool
    {
        $unique = (new $data['equivalent'])->whereRaw(($data['parameters']['key'] ?? $data['key']) . " = :value", ['value' => $data['value']]);
        # By the model's key, not the literal `id` - a uuid/char key names it differently.
        if ($ex = @$data['parameters']['ex']) $unique->where($unique->getPrimary() ?? 'id', '!=', $ex);
        if ($unique->count()) return false;
        return true;
    }
}
