<?php

namespace zFramework\Core\Validator\Rules;

use zFramework\Core\Validator\Rule;

class Unique extends Rule
{
    public function handle(array $data): bool
    {
        $unique = (new $data['equivalent'])->whereRaw(($data['parameters']['key'] ?? $data['key']) . " = :value", ['value' => $data['value']]);
        if ($ex = @$data['parameters']['ex']) $unique->where('id', '!=', $ex);
        if ($unique->count()) return false;
        return true;
    }
}
