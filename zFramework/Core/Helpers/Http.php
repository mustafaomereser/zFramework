<?php

namespace zFramework\Core\Helpers;

class Http
{
    /**
     * Which set of error views abort() renders from.
     *
     * Set it from middleware to serve a different set of error pages - the
     * skeleton points it at errors.admin for a signed-in admin. Reset after each
     * request, so one visitor's choice does not decide for the next.
     */
    const DEFAULT_ERROR_VIEW = 'errors.app';

    static $error_view = self::DEFAULT_ERROR_VIEW;

    /**
     * @return void
     */
    public static function flushRequestState(): void
    {
        self::$error_view = self::DEFAULT_ERROR_VIEW;
    }

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
