<?php

namespace zFramework\Core\Facades;

use App\Models\User;
use zFramework\Core\Crypter;

class Auth
{
    /**
     * When you use ::user() method you must fill that and if you again use ::user() get from this parameter.
     */
    static $user = null;

    public static function init()
    {
        if (!self::check() && $api_token = Cookie::get('auth-stay-in')) self::attempt(['api_token' => $api_token]);
    }

    /**
     * Login from a User model result array.
     * @param array $user
     * @return bool
     */
    public static function login(array $user): bool
    {
        if (isset($user['id'])) {
            Cookie::set('auth-password', $user['password']);
            Cookie::set('auth-token', $user['id']);
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
        return self::login((new User)->select('id')->where('api_token', $token)->first());
    }

    /**
     * Logout User
     * @return bool
     */
    public static function logout(): bool
    {
        self::$user = null;
        Cookie::delete('auth-stay-in');
        Cookie::delete('auth-token');
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
        if (!$user_id = Cookie::get('auth-token')) return false;
        if (self::$user == null) self::$user = (new User)->where('id', $user_id)->first(); // ->where('api_token', 'test', 'OR')
        if (!@self::$user['id'] || self::$user['password'] != Cookie::get('auth-password')) return self::logout();
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

        $user = (new User)->select(['id', 'api_token', 'password']);
        foreach ($fields as $key => $val) $user->where($key, ($key != 'password' ? $val : Crypter::encode($val)));
        $user = $user->first();

        if (@$user['id']) {
            self::login($user);
            if ($staymein) Cookie::set('auth-stay-in', $user['api_token'], time() * 2);
            return true;
        }

        return false;
    }

    /**
     * Get Current logged in user's id
     * @return integer
     */
    public static function id(): int
    {
        return self::user()['id'];
    }
}
