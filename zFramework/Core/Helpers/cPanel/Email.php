<?php

namespace zFramework\Core\Helpers\cPanel;

class Email
{
    public static function list(): ?array
    {
        return API::request("Email/list_pops");
    }

    public static function create(string $email, string $password, int $quota = 250): ?array
    {
        return API::request("Email/add_pop", compact('email', 'password', 'quota'));
    }

    public static function delete(string $user): ?array
    {
        return API::request("Email/delete_pop", ["email" => $user]);
    }
}
