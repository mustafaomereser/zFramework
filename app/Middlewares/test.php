<?php

namespace App\Middlewares;

class test
{
    public function __construct()
    {
        // middleware code
    }

    // (optional) if you don't need that, you can remove. !!! BUT if you use that middleware in Routes you need this.
    public function error()
    {
        abort();
    }
}
