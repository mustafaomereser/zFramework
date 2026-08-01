<?php

namespace zFramework\Core\Facades;

class Config
{
    /**
     * Configs path
     */
    static $path   = null;
    static $caches = [];

    /**
     * parseUrl() answers, keyed by the lookup that produced them.
     *
     * Resolving one costs a file_exists() per dotted segment, and get() is called
     * from hot paths - DB::prepare() asks for app.debug on every single query, so
     * a busy request used to make hundreds of stat calls to be told the same thing
     * every time. $caches below only ever skipped the include(), never this.
     */
    private static array $paths = [];

    public static function init()
    {
        self::$path = base_path('config');
    }

    /**
     * @param string $config
     * @return array|bool
     */
    private static function parseUrl(string $config): array|bool
    {
        if (isset(self::$paths[$config])) return self::$paths[$config];
        $lookup = $config;

        $config = explode(".", $config);

        $find = "";
        foreach ($config as $key => $file) {
            $find .= "/$file";
            unset($config[$key]);
            if (file_exists($config_path = self::$path . $find . ".php")) {
                $config_name = $file;
                break;
            }
        }

        // if (!isset($config_name)) return false;

        $output['name'] = $config_name ?? false;
        $output['path'] = $config_path;
        $output['find'] = substr($find, 1);

        $output['args'] = implode('.', array_filter($config, fn($var) => strlen((string) $var)));
        if (isset($output['args']) && !$output['args']) unset($output['args']);

        return self::$paths[$lookup] = $output;
    }

    /**
     * Config is exists check.
     * @param string $config
     * @return bool
     */
    public static function exists(string $config): bool
    {
        $path = self::parseUrl($config)['path'];
        if (file_exists($path)) return true;
        return false;
    }

    /**
     * Get Config
     * @param string $config
     * @return string|array|object
     */
    public static function get(string $config, bool $returnbool = true)
    {
        $data = self::parseUrl($config);
        if ($data === false) return $returnbool ? false : $config;
        $cache_name = str_replace('/', '.', $data['find']);

        $cache  = isset(self::$caches[$cache_name]);
        $config = $cache ? self::$caches[$cache_name] : include($data['path']);
        if (!$cache) self::$caches[$cache_name] = $config;

        if (isset($data['args'])) foreach (explode('.', $data['args']) as $key) if (isset($config[$key])) $config = $config[$key];
        return $config;
    }

    /**
     * A framework setting, from config/framework.php.
     *
     * Falls back to the old per-subject file when framework.php has no answer:
     * config('framework.view.caching'), then config('view.caching'). An
     * application that has not moved its settings across keeps working, and one
     * that has can delete the old files.
     *
     * The fallback is a plain get(), so a key missing from both behaves exactly
     * as it always did.
     *
     * @param string $key Dotted path below the subject - 'view.caching'.
     * @return mixed
     */
    public static function framework(string $key): mixed
    {
        static $framework = null;
        $framework ??= self::exists('framework') ? (array) self::get('framework') : [];

        $parts   = explode('.', $key);
        $subject = $parts[0];

        # framework.php says nothing about this subject, so the old file is still
        # the source of truth for it - if there is one. exists() rather than a
        # bare get(), which would try to include config/profiling/enabled.php and
        # warn about a file nobody ever meant to write.
        if (!array_key_exists($subject, $framework)) return self::exists($subject) ? self::get($key) : null;

        # It does, so this file answers for the whole branch. A key missing below
        # it is missing, not a reason to go looking on disk.
        $value = $framework;
        foreach ($parts as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) return null;
            $value = $value[$part];
        }

        return $value;
    }

    /**
     * Forget what has been read and resolved so far.
     * @return void
     */
    public static function clearCache(): void
    {
        self::$caches = [];
        self::$paths  = [];
    }

    /**
     * Update Config set veriables.
     * @param string $config
     * @param array $sets
     * @param bool $compare
     * @return bool
     */
    public static function set(string $config, array $sets, bool $compare = false): bool
    {
        $path = self::parseUrl($config)['path'];

        if ($compare == true) {
            $data = self::get($config);
            foreach ($sets as $key => $set) $data[$key] = $set;
        } else $data = $sets;

        $written = file_put_contents2($path, "<?php \nreturn " . var_export($data, true) . ";");

        if ($written !== false) {
            # Otherwise the in-memory copy keeps serving what was just overwritten.
            self::clearCache();

            # Invalidate here, where a config file actually changes - not in get(),
            # which used to do it on every first read of every config file. That
            # threw each one out of opcache per request and forced a recompile,
            # which is precisely what opcache exists to avoid. Writing is rare;
            # reading happens on every request.
            if (function_exists('opcache_invalidate')) opcache_invalidate($path, true);
        }

        return $written;
    }
}
