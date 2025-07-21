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
        if (!Auth::check()) {
            if ($api_token = @$_COOKIE['auth_stay_in']) Auth::attempt(['api_token' => Crypter::decode($api_token)]);
        }
    }

    /**
     * Login from a User model result array.
     * @param array $user
     */
    public static function login(array $user): bool
    {
        if (isset($user['id'])) {
            $_SESSION['user_id'] = $user['id'];
            return true;
        }

        return false;
    }

    /**
     * Login with user's api_token
     * @param string $token
     */
    public static function token_login(string $token)
    {
        $user = (new User)->where('api_token', $token)->first();
        if (isset($user['id'])) self::login($user);
    }

    /**
     * Logout User
     * @return bool
     */
    public static function logout(): bool
    {
        self::$user = null;
        setcookie('auth_stay_in', '', (time() - 60), '/');
        unset($_SESSION['user_id']);
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
        if (!isset($_SESSION['user_id'])) return false;

        if (self::$user == null) self::$user = (new User)->where('id', '=', $_SESSION['user_id'])->first(); // ->where('api_token', '=', 'test', 'OR')
        if (!@self::$user['id']) return self::logout();

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

        $user = (new User)->select(['id', 'api_token']);
        foreach ($fields as $key => $val) $user->where($key, ($key != 'password' ? $val : Crypter::encode($val)));
        $user = $user->first();

        if (@$user['id']) {
            $_SESSION['user_id'] = $user['id'];
            if ($staymein) setcookie('auth_stay_in', Crypter::encode($user['api_token']), (time() + (86400 * 360)), '/');
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
