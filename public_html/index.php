<?php
// no cache
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
//

define('PUBLIC_DIR', __DIR__);
define('BASE_PATH', dirname(__DIR__)); # you can move framework location. for example: define('BASE_PATH', dirname(__DIR__) . "/zframework");
include(BASE_PATH . '/zFramework/bootstrap.php');
zFramework\Run::begin();
