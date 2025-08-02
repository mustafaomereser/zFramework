<?php

namespace App\Requests\Welcome;

use zFramework\Core\Abstracts\Request;

class CommandRequest extends Request
{

    public function __construct()
    {
        $this->authorize = false;
    }

    public function columns(): array
    {
        return [
            'command' => ['nullable']
        ];
    }
}
