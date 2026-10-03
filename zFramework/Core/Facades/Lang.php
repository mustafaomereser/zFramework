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
     * @return string|false
     */
    private static function canSelect(string $lang): string|false
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
        if (!$path = self::canSelect($lang)) {
            # app.lang, then whatever directory exists - each only once, and with
            # the caller's $syncCookie. Retrying app.lang when its directory was
            # missing recursed until the stack ran out, and the retry wrote the
            # cookie a cookieless caller had asked not to write.
            foreach ([Config::get('app.lang'), self::list()[0] ?? null] as $fallback)
                if (strlen((string) $fallback) && $fallback !== $lang && self::canSelect($fallback)) return self::locale($fallback, $syncCookie);

            # No language directory at all: nothing to translate from, get() is null.
            self::$locale = (string) (Config::get('app.lang') ?? $lang);
            return false;
        }
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
     * Choose a locale when nothing has: locale()'s own order - Accept-Language,
     * then app.lang (which is all there is outside a request).
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

        # The application's file first, then the core's own English for the keys
        # the core reads (zFramework/Core/lang). resource/lang is the application's
        # and `update` never writes it, so a key newer than the project - a new
        # validator rule, a new error - came back null and its message was blank.
        $lang = self::lookup((string) self::$path, $name);
        if ($lang === null) $lang = self::lookup(FRAMEWORK_PATH . '/Core/lang', $name);

        foreach ($data as $key => $val) $lang = str_replace("{" . $key . "}", $val ?? '', $lang);

        return $lang;
    }

    /**
     * One dotted key in one language directory, or null.
     *
     * @param string $dir
     * @param array  $name the key split on dots; the first part names the file
     * @return mixed
     */
    private static function lookup(string $dir, array $name): mixed
    {
        $file = "$dir/{$name[0]}.php";
        if ($dir === '' || !is_file($file)) return null;

        $lang = include($file);
        foreach (array_slice($name, 1) as $val) $lang = is_array($lang) ? ($lang[$val] ?? null) : null;

        return $lang;
    }
}
