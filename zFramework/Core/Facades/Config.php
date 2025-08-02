<?php

namespace zFramework\Core\Facades;

class Config
{
    /**
     * Configs path
     */
    static $path   = null;
    static $caches = [];

    public static function init()
    {
        self::$path = base_path('config');
    }

    /**
     * @param string $config
     * @return array
     */
    private static function parseUrl(string $config): array
    {
        $config = explode(".", $config);
        $output['name'] = $config[0];
        $output['path'] = self::$path . "/" . $config[0] . ".php";
        unset($config[0]);
        $output['args'] = implode('.', array_filter($config));
        if (isset($output['args']) && !$output['args']) unset($output['args']);
        return $output;
    }

    /**
     * Get Config
     * @param string $config
     * @return string|array|object
     */
    public static function get(string $config)
    {
        $data = self::parseUrl($config);
        if (!is_file($data['path'])) return;

        $cache = isset(self::$caches[$data['name']]);
        if (!$cache && function_exists('opcache_invalidate')) opcache_invalidate($data['path'], true);
        $config = $cache ? self::$caches[$data['name']] : include($data['path']);
        if (!$cache) self::$caches[$data['name']] = $config;

        if (isset($data['args'])) foreach (explode('.', $data['args']) as $key) if (isset($config[$key])) $config = $config[$key];
        return $config;
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
        } else {
            $data = $sets;
        }

        return file_put_contents($path, "<?php \nreturn " . var_export($data, true) . ";");
    }
}
