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

    /**
     * Whether the caller would rather have JSON than a page.
     *
     * isAjax() answers for jQuery and the like, which send X-Requested-With; a
     * fetch(), a mobile client or a curl -H 'Accept: application/json' does not.
     * Read for the error page so that a client parsing JSON is not handed 140 KB
     * of HTML with a 500 in front of it.
     *
     * @return bool
     */
    public static function wantsJson(): bool
    {
        if (self::isAjax()) return true;

        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));

        # text/html first means a browser, whatever else it lists after it.
        return str_contains($accept, 'application/json') && !str_starts_with($accept, 'text/html');
    }

    // Abort to http response.
    public static function abort(int $code = 418, $message = null)
    {
        # wantsJson(), not isAjax(): a fetch() or curl -H 'Accept: application/json'
        # sends no X-Requested-With, and was handed the HTML error page to parse.
        if (self::wantsJson()) throw new \zFramework\Core\ResponseSignal($code, ['Content-Type' => 'application/json; charset=utf-8'], json_encode(compact('message', 'code'), JSON_UNESCAPED_UNICODE));

        $view = @view(self::$error_view . ".$code", compact('message', 'code'));
        throw new \zFramework\Core\ResponseSignal($code, [], !empty($view) ? $view : (string) $message);
    }
}
