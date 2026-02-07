<?php

namespace zFramework\Core\Facades;

use App\Models\User;
use zFramework\Core\Crypter;

class Auth
{
    /**
     * When you use ::user() method you must fill that and if you again use ::user() get from this parameter.
     */
    static $user     = null;

    /**
     * API mode for api requests. (Cookie not work with apis)
     */
    static $api_mode = false;

    /**
     * Columns for match
     */
    static private $columns;

    private static function getMode()
    {
        return self::$api_mode ? Session::class : Cookie::class;
    }

    public static function init()
    {
        self::$columns = (new User)->special_columns ?? ['email' => 'email', 'password' => 'password', 'passwordencode' => 'crypter'];
        if (!self::check() && $api_token = (self::getMode())::get('auth-stay-in')) self::attempt(['api_token' => $api_token]);
    }

    /**
     * Login from a User model result array.
     * @param array $user
     * @return bool
     */
    public static function login(array $user): bool
    {
        if (isset($user['id'])) {
            (self::getMode())::set('auth-password', $user[self::$columns['password']]);
            (self::getMode())::set('auth-token', $user['id']);
            return true;
        }

        return false;
    }

    /**
     * Login with user's api_token
     * @param string $token
     * @return bool
     */
    public static function token_login(string $token): bool
    {
        return self::login((new User)->select('id, ' . self::$columns['password'])->where('api_token', $token)->first());
    }

    /**
     * Logout User
     * @return bool
     */
    public static function logout(): bool
    {
        self::$user = null;
        (self::getMode())::delete('auth-stay-in');
        (self::getMode())::delete('auth-token');
        (self::getMode())::delete('auth-password');
        return true;
    }

    /**
     * Check User logged in
     * @return bool
     */
    public static function check(): bool
    {
        if (isset(self::user()['id'])) return true;
        return false;
    }

    /**
     * Get current logged user informations
     * @return array|self|bool
     */
    public static function user()
    {
        if (!$user_id = (self::getMode())::get('auth-token')) return false;
        if (self::$user == null) self::$user = (new User)->where('id', $user_id)->first(); // ->where('api_token', 'test', 'OR')
        if (!@self::$user['id'] || self::$user[self::$columns['password']] != (self::getMode())::get('auth-password')) return self::logout();
        return self::$user;
    }

    /**
     * Attempt for login.
     * @param array $fields
     * @param bool $staymein
     * @return bool
     */
    public static function attempt(array $fields = [], bool $staymein = false): bool
    {
        if (self::check()) return false;

        $user = (new User)->select('id, api_token, ' . self::$columns['password']);

        if (isset($fields[self::$columns['password']])) $fields[self::$columns['password']] = ['crypter' => fn() => Crypter::encode($fields[self::$columns['password']]), 'md5' => fn() => md5($fields[self::$columns['password']])][self::$columns['passwordencode']]();

        foreach ($fields as $key => $value) $user->where($key, $value);
        $user = $user->first();

        if (@$user['id']) {
            self::login($user);
            if ($staymein) (self::getMode())::set('auth-stay-in', $user['api_token'], time() * 2);
            return true;
        }

        return false;
    }

    /**
     * Get Current logged in user's id
     * @return integer
     */
    public static function id(): int|null
    {
        return self::user()['id'] ?? null;
    }
}
