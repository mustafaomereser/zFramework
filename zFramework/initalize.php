<?php
define('FRAMEWORK_PATH', __DIR__);
define('FRAMEWORK_VERSION', '2.6.5');
header("X-Powered-By: zFramework v" . FRAMEWORK_VERSION);

// Initalize settings
date_default_timezone_set('Europe/Istanbul');

// Session: Start
$storage_path = FRAMEWORK_PATH . "/storage";
$sessions_path = "$storage_path/sessions";
@mkdir($sessions_path, 0777, true);
session_save_path($sessions_path);
ini_set('session.gc_probability', 1);

session_start(); # disable for Session::class
// Session: End

$GLOBALS['databases'] = [
    'connected'   => [],
    'connections' => include(BASE_PATH . '/database/connections.php') #db connections strings
];

include(BASE_PATH . '/zFramework/run.php');
include(BASE_PATH . '/zFramework/vendor/autoload.php');

spl_autoload_register(function ($class) {
    zFramework\Run::includer(BASE_PATH . "/$class.php");
    if (method_exists($class, 'init')) $class::init();
});
