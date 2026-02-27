<?php

namespace zFramework\Kernel\Helpers;

use ReflectionClass;
use ReflectionMethod;

class Module
{
    static $list = [];

    public static function getModules()
    {
        $list = [];
        $path = FRAMEWORK_PATH . "/Kernel/Modules";
        foreach (glob("$path/*.php") as $module) if (!strstr($module, '---')) $list[strtolower(str_replace(["$path/", '.php'], '', $module))] = [
            'methods' => self::classMethods($module)
        ];

        self::$list = $list;
        return new self();
    }

    public static function classMethods($class, $flags = ReflectionMethod::IS_PUBLIC)
    {
        $methods = (new ReflectionClass((str_replace("/", "\\", str_replace([BASE_PATH, '.php'], '', $class)))))->getMethods($flags);
        return array_values(array_map(
            fn($m) => [
                'name' => $m->name,
                'doc'  => str_replace(['*', '/'], '', $m->getDocComment() ?: '')
            ],
            array_filter(
                $methods,
                fn($m) => strlen($m->getDocComment())
            )
        ));
    }
}
