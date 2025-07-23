<?php

namespace zFramework\Core\Facades;

class Config
{
    /**
     * Configs path
     */
    static $path = "config";

    /**
     * @param string $config
     * @param bool $justConfig
     * @return array
     */
    private static function parseUrl(string $config, bool $justConfig = false): array
    {
        $config = explode(".", $config);
        $path   = base_path(self::$path);
        $arg    = "";

        $config_found = 0;
        foreach ($config as $row) {
            if (!$config_found) {
                $path .= "/$row";
                if (is_file("$path.php")) {
                    $config_found = 1;
                    $path .= ".php";
                }
            } elseif (!$justConfig) {
                $arg .= ".$row";
            }
        }

        $return = [$path];
        if ($arg) $return[] = ltrim($arg, ".");

        return $return;
    }

    /**
     * Get Config
     * @param string $config
     * @return string|array|object
     */
    public static function get(string $config)
    {
        $arr = self::parseUrl($config);
        if (!is_file($arr[0])) return;

        if (function_exists('opcache_invalidate')) opcache_invalidate($arr[0], true);

        $config = include($arr[0]);
        if (isset($arr[1])) foreach (explode('.', $arr[1]) as $key) if (isset($config[$key])) $config = $config[$key];
        return $config;
    }

    /**
     * Update Config set veriables.
     * @param string $config
     * @param array $sets
     * @param bool $compare
     * @return void
     */
    public static function set(string $config, array $sets, bool $compare = true)
    {
        $path = self::parseUrl($config, true)[0];

        if ($compare == true) {
            $data = self::get($config);
            foreach ($sets as $key => $set)
                if ($set !== 'CONF_VAR_UNSET') $data[$key] = $set;
                else unset($data[$key]);
        } else {
            $data = $sets;
        }

        file_put_contents(strstr($path, '.php') ? $path : "$path.php", "<?php \nreturn " . var_export($data, true) . ";");
    }
}
