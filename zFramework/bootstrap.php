<?php
define('FRAMEWORK_PATH', __DIR__);
define('FRAMEWORK_VERSION', '3.0.0');
$app_config = include(BASE_PATH . "/config/app.php");
if ($app_config['x-powered-by'] ?? true) header("X-Powered-By: zFramework v" . FRAMEWORK_VERSION);

// Initalize settings
date_default_timezone_set('Europe/Istanbul');

// Keep call arguments in stack traces so the error page can show them.
// Debug only: with this on, every argument is retained on the exception and ends up
// in the error logs too - a failed login would write the plain password next to the
// trace, and 100 KB per string parameter is a lot of memory to hold on production.
if ($app_config['debug'] ?? false) {
    ini_set('zend.exception_ignore_args', 0);
    ini_set('zend.exception_string_param_max_len', 100000);
}

// Session settings: Start
$storage_path = FRAMEWORK_PATH . "/storage";
if (!isset($cron_mode)) {
    // Included directly rather than through Config, which is not loaded this
    // early. Falls back to the old standalone files for applications that have
    // not moved their settings into framework.php.
    //
    // Redis only when the session driver asks for it - an include per request is
    // not free on a network filesystem.
    $framework      = @include(BASE_PATH . "/config/framework.php") ?: [];

    # Config::framework() included this same file a second time for its own
    # cache; hand it what was just read. With no file the global is never set and
    # Config falls back to one config file per subject.
    if (is_array($framework) && $framework) $GLOBALS['framework_config'] = $framework;

    $session_config = $framework['session'] ?? (@include(BASE_PATH . "/config/session.php") ?: []);
    $redis_config   = ($session_config['driver'] ?? 'file') === 'redis'
        ? ($framework['redis'] ?? (@include(BASE_PATH . "/config/redis.php") ?: []))
        : [];

    if (($session_config['driver'] ?? 'file') === 'redis' && ($redis_config['enabled'] ?? false)) {
        // Sessions in Redis: every app server reads the same store, so a user is
        // recognised whichever one the load balancer picks. Session::load()'s
        // early-unlock behaviour is unaffected - only the storage changes.
        $auth = !empty($redis_config['password']) ? '?auth=' . urlencode($redis_config['password']) : '';
        $db   = $redis_config['database']['session'] ?? 0;
        $path = 'tcp://' . ($redis_config['host'] ?? '127.0.0.1') . ':' . ($redis_config['port'] ?? 6379) . $auth . ($auth ? '&' : '?') . 'database=' . $db;

        ini_set('session.save_handler', 'redis');
        ini_set('session.save_path', $path);
        // Redis expires keys itself; PHP's collector has nothing to scan.
        ini_set('session.gc_probability', 0);
    } else {
        $sessions_path = "$storage_path/sessions";

        # Checked before creating: a recursive mkdir() stats every segment of the
        # path on every request, whether or not the directory is already there.
        if (!is_dir($sessions_path)) @mkdir($sessions_path, 0777, true);

        session_save_path($sessions_path);
        ini_set('session.gc_probability', $session_config['gc_probability'] ?? 1);
    }
}
// Session settings: End

// Error log: start
define('ERROR_LOG_DIR', BASE_PATH . '/error_logs');
// Error log: end

$GLOBALS['databases'] = [
    'connected'   => [],
    'connections' => include(BASE_PATH . '/database/connections.php') #db connections strings
];

if (!isset($cron_mode) && ($app_config['force-https'] ?? false) && (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === "off")) die(header('Location: https://' . ($_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'])));

include(FRAMEWORK_PATH . '/vendor/autoload.php');
include(FRAMEWORK_PATH . '/run.php');

spl_autoload_register(function ($class) {
    zFramework\Run::includer(BASE_PATH . "/$class.php");
    if (method_exists($class, 'init')) $class::init();
});
