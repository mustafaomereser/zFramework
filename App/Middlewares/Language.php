<?php

namespace App\Middlewares;

use zFramework\Core\Facades\Cookie;
use zFramework\Core\Facades\Lang;

class Language
{
    public function attempt()
    {
        Lang::locale(Cookie::get('lang') ?? null);
        return true;
    }
}
