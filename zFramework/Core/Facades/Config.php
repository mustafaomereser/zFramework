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
     * Compiled config for this request: array once loaded, false when there is no
     * cache file, null before the first lookup.
     */
    private static array|false|null $compiled = null;

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

        return $output;
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
        # Compiled cache (php terminal config cache): every config file merged into
        # one array, so a request includes one file instead of a dozen and skips the
        # per-lookup opcache_invalidate() below. Files with closures are left out of
        # the compile and fall through to the normal path.
        if (($compiled = self::compiled()) !== false) {
            $parts = explode('.', $config);
            $file  = array_shift($parts);

            if (array_key_exists($file, $compiled)) {
                $value = $compiled[$file];
                # Same traversal as the uncached path below, including its quirk:
                # a key that does not exist is skipped rather than failing, so the
                # level above is what comes back. Callers depend on that.
                foreach ($parts as $key) if (isset($value[$key])) $value = $value[$key];
                return $value;
            }
        }

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
     * Where the compiled config lives.
     * @return string
     */
    public static function cachePath(): string
    {
        return FRAMEWORK_PATH . '/storage/config.cache.php';
    }

    /**
     * Load the compiled config once per request. false when there is none.
     * @return array|false
     */
    private static function compiled(): array|false
    {
        if (self::$compiled !== null) return self::$compiled;
        if (!defined('FRAMEWORK_PATH') || !is_file($path = self::cachePath())) return self::$compiled = false;

        $data = include $path;
        return self::$compiled = is_array($data) ? $data : false;
    }

    /**
     * Drop the compiled config, in memory and on disk.
     * @return void
     */
    public static function clearCache(): void
    {
        self::$compiled = null;
        self::$caches   = [];
        if (defined('FRAMEWORK_PATH')) @unlink(self::cachePath());
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
            # A compiled cache would keep serving what was just overwritten.
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
