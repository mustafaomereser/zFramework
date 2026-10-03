<?php

namespace zFramework\Core\Facades;

class Lang
{
    static $locale = null;
    static $path = null;

    /**
     * Drop the resolved locale.
     *
     * It comes from the visitor's cookie or browser, so keeping it would serve
     * the next request in the previous visitor's language.
     *
     * @return void
     */
    public static function flushRequestState(): void
    {
        self::$locale = null;
    }

    /**
     * is selectable?
     * @param string $lang
     * @return string
     */
    private static function canSelect(string $lang): string
    {
        # A locale is letters, digits and dashes - `..` and `./` are directories
        # too, and Accept-Language is the visitor's to write.
        if (!preg_match('/^[A-Za-z0-9_-]{1,20}$/', $lang)) return false;

        $path = base_path("resource/lang/$lang");
        if (!is_dir($path)) return false;
        return $path;
    }

    /**
     * Lang list
     * @return array
     */
    public static function list(): array
    {
        return scan_dir(base_path("resource/lang"));
    }

    /**
     * Set Locale
     * @param ?string $lang
     * @param bool $syncCookie
     * @return bool|self
     */
    public static function locale(?string $lang = null, bool $syncCookie = true): bool
    {
        $lang = strlen((string) $lang) ? $lang : (substr($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? (config('app.lang') ?? ''), 0, 2));
        if (!$path = self::canSelect($lang)) return self::locale(Config::get('app.lang') ?? self::list()[0]);
        if ($syncCookie) Cookie::set('lang', $lang, time() * 2);

        self::$locale = $lang;
        self::$path = $path;

        return true;
    }

    /**
     * Current Locale
     * @return string
     */
    public static function currentLocale(): string
    {
        self::ensureLocale();
        return self::$locale;
    }

    /**
     * Fall back to app.lang when nothing chose a locale.
     *
     * The Language middleware chooses one per request. A terminal command, a
     * scheduled task or a cron script runs no middleware, so currentLocale()
     * threw a TypeError and every get() came back null - a scheduled mail
     * rendered without a single translated string. No cookie is written: there is
     * no visitor to remember it for.
     *
     * @return void
     */
    private static function ensureLocale(): void
    {
        if (self::$locale === null || self::$path === null) self::locale(null, false);
    }

    /**
     * Get Lang string or array
     * @param string $_name
     * @param array $data
     * @return array|string
     */
    public static function get(string $_name, array $data = [])
    {
        self::ensureLocale();

        $name = explode('.', $_name);
        $lang = self::$path . "/" . $name[0] . ".php";
        if (!is_file($lang)) return null;

        $lang = include($lang);
        unset($name[0]);

        foreach ($name as $val) $lang = $lang[$val] ?? null;
        foreach ($data as $key => $val) $lang = str_replace("{" . $key . "}", $val ?? '', $lang);

        return $lang;
    }
}
