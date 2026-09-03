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
        # Class name to command name: PushNotification is typed push-notification.
        # Single-word modules are unaffected, which is all of the older ones.
        foreach (glob("$path/*.php") as $module) if (!strstr($module, '---')) $list[strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', str_replace(["$path/", '.php'], '', $module)))] = [
            'methods' => self::classMethods($module)
        ];

        self::$list = $list;
        return new self();
    }

    public static function classMethods($class, $flags = ReflectionMethod::IS_PUBLIC)
    {
        # Both separators normalised first: BASE_PATH may be spelled with / while
        # the path arrives with \ (or the reverse, depending on who defined it),
        # and an unreplaced prefix turned the class name into a filesystem path.
        $class   = str_replace('\\', '/', $class);
        $methods = (new ReflectionClass((str_replace("/", "\\", str_replace([str_replace('\\', '/', BASE_PATH), '.php'], '', $class)))))->getMethods($flags);
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
