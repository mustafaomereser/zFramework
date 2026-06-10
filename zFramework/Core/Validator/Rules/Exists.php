<?php

namespace zFramework\Core\Validator\Rules;

use zFramework\Core\Validator\Rule;

class Exists extends Rule
{
    public function handle(array $data): bool
    {
        $exists = (new $data['equivalent'])->whereRaw(($data['parameters']['key'] ?? $data['key']) . " = :value", ['value' => $data['value']]);
        if ($ex = @$data['parameters']['ex']) $exists->where('id', '!=', $ex);
        if ($exists->count()) return true;
        return false;
    }
}
