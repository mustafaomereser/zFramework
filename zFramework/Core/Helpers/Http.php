<?php

namespace zFramework\Core\Helpers;

class Http
{
    /**
     * Which set of error views abort() renders from.
     *
     * Application code picks this per request - the skeleton's ViewDirectives
     * middleware points it at errors.admin for an authenticated admin. There is
     * no else branch to such a decision, so in a worker the last request that
     * changed it decided for everyone after it: a plain visitor's 404 came back
     * as the admin error page. Reset below.
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
