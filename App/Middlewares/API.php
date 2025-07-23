<?php

namespace App\Middlewares;

use zFramework\Core\Facades\Auth;

#[\AllowDynamicProperties]
class API
{
    public function attempt()
    {
        Auth::logout();

        if (@$auth_token = getallheaders()['Auth-Token']) {
            Auth::token_login($auth_token);
        }

        return true;
    }
}
