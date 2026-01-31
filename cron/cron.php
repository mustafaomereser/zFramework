<?php
$cron_mode = true;
define('BASE_PATH', str_replace('\\', '/', dirname(__DIR__)));
include(BASE_PATH . '/zFramework/bootstrap.php');
zFramework\Run::includer('../zFramework/modules', false);
