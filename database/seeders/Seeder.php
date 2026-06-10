<?php

namespace Database\Seeders;

use App\Models\User;
use zFramework\Core\Facades\Auth;
use zFramework\Core\Facades\Str;

class Seeder
{
    public function __construct()
    {
        //
    }

    public function seed()
    {
        (new User)->insert([
            'username'  => 'admin',
            'password'  => Auth::encodePassword('admin'),
            'email'     => 'admin@localhost.com',
            'api_token' => Str::rand(60)
        ]);

        for ($i = 0; $i < 50; $i++) (new User)->insert([
            'username'  => Str::rand(15),
            'password'  => Auth::encodePassword('admin'),
            'email'     => Str::rand(15) . '@localhost.com',
            'api_token' => Str::rand(60)
        ]);
    }

    public function destroy()
    {
        (new User)->prepare('TRUNCATE users');
        return $this;
    }
}
