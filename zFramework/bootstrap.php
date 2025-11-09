<?php
define('FRAMEWORK_PATH', __DIR__);
define('FRAMEWORK_VERSION', '2.8.0');
header("X-Powered-By: zFramework v" . FRAMEWORK_VERSION);

// Initalize settings
date_default_timezone_set('Europe/Istanbul');

// Session settings: Start
$storage_path = FRAMEWORK_PATH . "/storage";
$sessions_path = "$storage_path/sessions";
@mkdir($sessions_path, 0777, true);
session_save_path($sessions_path);
ini_set('session.gc_probability', 1);
// Session settings: End

// Error log: start
define('ERROR_LOG_DIR', BASE_PATH . '/error_logs');

function error_log_callback($last_log)
{
    return;
}
// Error log: end

$GLOBALS['databases'] = [
    'connected'   => [],
    'connections' => include(BASE_PATH . '/database/connections.php') #db connections strings
];

if (((include(BASE_PATH . "/config/app.php"))['force-https'] ?? false) && (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === "off")) die(header('Location: https://' . ($_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'])));

include(BASE_PATH . '/zFramework/vendor/autoload.php');
include(BASE_PATH . '/zFramework/run.php');

spl_autoload_register(function ($class) {
    zFramework\Run::includer(BASE_PATH . "/$class.php");
    if (method_exists($class, 'init')) $class::init();
});
