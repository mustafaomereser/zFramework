<?php

namespace zFramework\Kernel\Modules;

use zFramework\Kernel\Terminal;
use zFramework\Run;

/**
 * `php terminal state check` - finds static properties that would leak from one
 * request into the next under a long-running worker.
 *
 * Only matters for RoadRunner and similar: under PHP-FPM the process dies after
 * each request and takes its statics with it. Where it does matter, a static
 * left holding request data hands it to the next visitor - their identity,
 * language, or the error views an admin page selected.
 *
 * Run it after adding a static to a framework class, and before a release.
 */
class State
{
    /**
     * Statics deliberately kept between requests, and why.
     *
     * Keeping the route table, view binds and database handles is the point of
     * booting once. Add a static here when it should survive; otherwise clear it
     * in the class's flushRequestState(). Anything in neither is reported.
     */
    private const BOOT_STATE = [
        'zFramework\Run::$loadtime'                    => 'set once at boot',
        'zFramework\Run::$included'                    => 'trimmed to the boot snapshot by resetState()',
        'zFramework\Run::$modules'                     => 'the module list, discovered at boot',
        'zFramework\Core\Route::$routes'               => 'the route table - the reason to boot once',
        'zFramework\Core\Route::$caching'              => 'read from config/route.php at boot',
        'zFramework\Core\Route::$index'                => 'derived from $routes, rebuilt with it',
        'zFramework\Core\View::$binds'                 => 'registered by providers at boot',
        'zFramework\Core\View::$config'                => 'view settings, set at boot',
        'zFramework\Core\View::$directives'            => 'registered by middleware, re-registered per request',
        'zFramework\Core\Facades\Auth::$model'         => 'the user model instance',
        'zFramework\Core\Facades\Auth::$database_exists' => 'whether the auth connection is configured',
        'zFramework\Core\Facades\Auth::$columns'       => 'column map from the model',
        'zFramework\Core\Facades\Auth::$redisEnabled' => 'whether config asks for redis',
        'zFramework\Core\Facades\Config::$path'        => 'the config directory',
        'zFramework\Core\Facades\Config::$caches'      => 'parsed config files - they do not change per request',
        'zFramework\Core\Facades\Config::$paths'       => 'resolved config lookups, same reasoning',
        'zFramework\Core\Facades\Cookie::$options'     => 'cookie defaults from config',
        'zFramework\Core\Facades\Redis::$connections'  => 'the connections themselves, kept on purpose',
        'zFramework\Core\Facades\Redis::$config'       => 'config/redis.php',
        'zFramework\Core\Facades\Redis::$extension'    => 'whether ext-redis is loaded',
        'zFramework\Core\Facades\DB::$schemeMtimes'    => 'guarded by $schemeChecked, which is cleared',
        'zFramework\Core\Crypter::$key'                => 'from config/crypt.php',
        'zFramework\Core\Crypter::$salt'               => 'from config/crypt.php',
        'zFramework\Core\Csrf::$timeOut'               => 'a constant in all but name',
        'zFramework\Core\GlobalCache::$prefix'         => 'derived from BASE_PATH',
        'zFramework\Core\GlobalCache::$apcu'           => 'whether APCu may be used',
        'zFramework\Core\GlobalCache::$redisEnabled'   => 'whether config asks for redis',
        'zFramework\Core\Facades\Lang::$path'          => 'the language directory',
        'zFramework\Core\Facades\Mail::$security'      => 'transport constants',
        'zFramework\Core\Helpers\File::$mimeMap'       => 'a lookup table',
        'zFramework\Core\Helpers\Http::$error_view'    => 'reset by flushRequestState()',
        'zFramework\Core\Validator::$ruleMap'          => 'a lookup table',
        'zFramework\Core\Facades\JustOneTime::$session_name' => 'a fixed session key',

        # Credentials, and safe only while set once at boot as the class
        # documents. If you set them per request - a tenant's own cPanel account,
        # say - clear them yourself at the end of it, or a worker will carry one
        # tenant's token into the next tenant's request.
        'zFramework\Core\Helpers\cPanel\API::$domain'    => 'configured at boot - see the note above if you set it per request',
        'zFramework\Core\Helpers\cPanel\API::$username'  => 'configured at boot - see the note above if you set it per request',
        'zFramework\Core\Helpers\cPanel\API::$apiToken'  => 'configured at boot - see the note above if you set it per request',
        'zFramework\Core\Helpers\cPanel\API::$verifySSL' => 'a fixed setting',
    ];

    /**
     * Namespaces to walk. Kernel/ is CLI-only and never serves a request.
     */
    private const SCAN = [
        'Core' => 'zFramework\Core',
    ];

    public static function begin($methods)
    {
        if (!in_array(@Terminal::$commands[1], $methods)) return Terminal::text('[color=red]You must select in method list: ' . implode(', ', $methods) . '[/color]');
        self::{Terminal::$commands[1]}();
    }

    /**
     * Description: Report statics that would leak between requests in a worker
     * Usage: php terminal state check
     */
    public static function check()
    {
        $tracked = self::tracked();
        $leaks   = [];
        $unknown = [];

        foreach (self::classes() as $class) {
            try {
                $reflection = new \ReflectionClass($class);
            } catch (\Throwable) {
                continue;
            }

            if ($reflection->isInterface() || $reflection->isAbstract() || $reflection->isEnum()) continue;

            $flushed = self::flushedBy($reflection);

            foreach ($reflection->getProperties(\ReflectionProperty::IS_STATIC) as $property) {
                # Declared by a parent or a trait: it is reported where it lives.
                if ($property->getDeclaringClass()->getName() !== $class) continue;

                $name = $class . '::$' . $property->getName();

                if (isset(self::BOOT_STATE[$name])) continue;
                if (in_array($property->getName(), $flushed, true)) continue;

                if (isset($tracked[$class])) $leaks[] = [$name, 'in REQUEST_STATE, but flushRequestState() does not clear it'];
                else $unknown[] = [$name, 'class is not in Run::REQUEST_STATE'];
            }
        }

        Terminal::text("[color=dark-gray]Checked " . count(self::classes()) . " class(es) under " . implode(', ', self::SCAN) . "[/color]\n");

        if (!count($leaks) && !count($unknown)) {
            Terminal::text("[color=green]No leaks: every static is either cleared per request or a declared boot state.[/color]");
            return;
        }

        foreach ([['Cleared nowhere', $leaks], ['Not tracked at all', $unknown]] as [$title, $rows]) {
            if (!count($rows)) continue;

            Terminal::text("[color=red]$title (" . count($rows) . ")[/color]");
            foreach ($rows as [$name, $why]) Terminal::text("[color=yellow]-> {$name}[/color] [color=dark-gray]{$why}[/color]");
            Terminal::text('');
        }

        Terminal::text("[color=dark-gray]Each one is a decision, not necessarily a bug. Either clear it in the[/color]");
        Terminal::text("[color=dark-gray]class's flushRequestState() and list the class in Run::REQUEST_STATE, or[/color]");
        Terminal::text("[color=dark-gray]record it in State::BOOT_STATE with the reason it may stay.[/color]");
    }

    /**
     * Classes named in Run::REQUEST_STATE.
     *
     * @return array class name => true
     */
    private static function tracked(): array
    {
        $reflection = new \ReflectionClass(Run::class);
        $constant   = $reflection->getConstant('REQUEST_STATE') ?: [];

        return array_fill_keys($constant, true);
    }

    /**
     * Property names a class's flushRequestState() assigns to.
     *
     * Read from the method's source rather than by running it: the check has to
     * work without a booted application, and running it would clear live state.
     * A flushRequestState() that delegates - View's calls reset() - is followed
     * one level down.
     *
     * @param \ReflectionClass $class
     * @return array
     */
    private static function flushedBy(\ReflectionClass $class): array
    {
        if (!$class->hasMethod('flushRequestState')) return [];

        $names = [];
        $seen  = [];
        $queue = ['flushRequestState'];

        while ($method = array_shift($queue)) {
            if (isset($seen[$method]) || !$class->hasMethod($method)) continue;
            $seen[$method] = true;

            $source = self::source($class->getMethod($method));
            if ($source === null) continue;

            preg_match_all('/self::\$(\w+)\s*=/', $source, $matches);
            foreach ($matches[1] as $name) $names[] = $name;

            # self::reset(), self::flush() and the like.
            preg_match_all('/self::(\w+)\s*\(/', $source, $calls);
            foreach ($calls[1] as $call) $queue[] = $call;
        }

        return array_unique($names);
    }

    /**
     * The source lines of one method.
     *
     * @param \ReflectionMethod $method
     * @return string|null
     */
    private static function source(\ReflectionMethod $method): ?string
    {
        $file = $method->getFileName();
        if (!$file || !is_file($file)) return null;

        $lines = @file($file);
        if ($lines === false) return null;

        return implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
    }

    /**
     * Every class under the scanned namespaces, loaded so it can be reflected.
     *
     * @return array
     */
    private static function classes(): array
    {
        static $classes = null;
        if ($classes !== null) return $classes;

        $classes = [];

        foreach (self::SCAN as $directory => $namespace) {
            $path = FRAMEWORK_PATH . '/' . $directory;
            if (!is_dir($path)) continue;

            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));

            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') continue;

                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($path) + 1));
                $class    = $namespace . '\\' . str_replace('/', '\\', substr($relative, 0, -4));

                # class_exists autoloads it, which is what makes reflection possible.
                if (class_exists($class)) $classes[] = $class;
            }
        }

        sort($classes);
        return $classes;
    }
}
