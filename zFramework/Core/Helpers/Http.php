<?php

namespace zFramework\Core\Helpers;

class Http
{
    static $error_view = 'errors.app';
    /**
     * Check is XMLHttpRequest Or Normal Request
     */
    public static function isAjax()
    {
        return isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest';
    }

    // Abort to http response.
    public static function abort(int $code = 418, $message = null)
    {
        if (self::isAjax()) throw new \zFramework\Core\ResponseSignal($code, ['Content-Type' => 'application/json; charset=utf-8'], json_encode(compact('message', 'code'), JSON_UNESCAPED_UNICODE));

        $view = @view(self::$error_view . ".$code", compact('message', 'code'));
        throw new \zFramework\Core\ResponseSignal($code, [], !empty($view) ? $view : (string) $message);
    }
}
