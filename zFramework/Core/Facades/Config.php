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

        $output['args'] = implode('.', array_filter($config, fn($var) => strlen((string) $var)));
        if (isset($output['args']) && !$output['args']) unset($output['args']);

        return $output;
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
