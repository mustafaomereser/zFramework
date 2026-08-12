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
     * Resolved lookups, keyed by the dotted string that produced them.
     *
     * Working out which file a lookup belongs to costs a file_exists() per
     * segment, and the answer cannot change within a request. $caches below
     * holds file contents; this holds where they were found.
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
     * Read a framework setting from config/framework.php.
     *
     *   Config::framework('view.caching')
     *   Config::framework('redis.database.session')
     *
     * Returns null when the setting is not configured anywhere, so callers can
     * supply their own default with ??.
     *
     * For backwards compatibility, a subject framework.php does not define falls
     * back to its own file - 'view.caching' to config/view.php - which is how an
     * application upgrades without moving its settings first.
     *
     * @param string $key Dotted path, starting with the subject.
     * @return mixed
     */
    public static function framework(string $key): mixed
    {
        static $framework = null;
        # bootstrap.php already read config/framework.php and left it here, so
        # the same file is not read and parsed twice.
        $framework ??= $GLOBALS['framework_config'] ?? (self::exists('framework') ? (array) self::get('framework') : []);

        $parts   = explode('.', $key);
        $subject = $parts[0];

        # Subject not in framework.php: use its own file if there is one. exists()
        # rather than get(), which would try to include config/<subject>/<key>.php
        # and warn about a path nobody meant to write.
        if (!array_key_exists($subject, $framework)) return self::exists($subject) ? self::get($key) : null;

        # Subject is here, so this file answers for all of it. A key missing below
        # it is simply missing.
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

            # opcache is holding the previous version of this file. Dropping it
            # here, on the rare write, rather than on every read.
            if (function_exists('opcache_invalidate')) opcache_invalidate($path, true);
        }

        return $written;
    }
}
