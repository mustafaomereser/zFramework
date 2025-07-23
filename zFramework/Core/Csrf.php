<?php

namespace zFramework\Core;

use zFramework\Core\Facades\Session;
use zFramework\Core\Facades\Str;

class Csrf
{
    /**
     * Csrf will change timeout is when finish
     */
    static $timeOut = (10 * 60);

    /**
     * Show csrf input
     */
    public static function csrf(): void
    {
        echo "<input type='hidden' name='_token' value='" . self::get() . "' />";
    }

    /**
     * Get Csrf Token
     * @return string
     */
    public static function get(): string
    {
        if ((!@Session::get('csrf_token') || time() > @Session::get('csrf_token_timeout'))) self::set();
        return Session::get('csrf_token');
    }

    /**
     * Csrf token history, only 2 token allowed.
     * @return array
     */
    private static function getStorage(): array
    {
        return Session::get('csrf_storage') ?? [];
    }

    /**
     * Storage csrf tokens, only history storage 2 csrf token.
     * @param $csrf
     * @return void
     */
    private static function addStorage(string $csrf): void
    {
        $tokens = self::getStorage();
        if (count($tokens) >= 2) unset($tokens[0]);
        $tokens[] = $csrf;
        Session::set('csrf_storage', array_values($tokens));
    }

    /**
     * Set Csrf Token randomly
     * @return void
     */
    public static function set(): void
    {
        Session::set('csrf_token_timeout', time() + self::$timeOut);
        Session::set('csrf_token', Str::rand(30));
        self::addStorage(Session::get('csrf_token'));
    }

    /**
     * Destroy Csrf Token
     */
    public static function unset(): void
    {
        Session::delete('csrf_token');
    }

    /**
     * Get remain time for timeout
     * @return int
     */
    public static function remainTimeOut(): int
    {
        return  Session::get('csrf_token_timeout') - time();
    }

    /**
     * Compare csrf token
     * @param string $token
     * @return bool
     */
    public static function compare(string $token): bool
    {
        return in_array($token, self::getStorage());
    }

    /**
     * Check is a valid Csrf Token
     * $alwaysTrue parameter: if you wanna do not check it you can use $alwaysTrue = true
     * @param bool $alwaysTrue
     * @return bool
     */
    public static function check(bool $alwaysTrue = false): bool
    {
        if ((method() != 'GET' && !self::compare(request('_token'))) && $alwaysTrue != true) return false;
        return true;
    }
}
