<?php
define('BASE_PATH', str_replace('\\', '/', dirname(__DIR__)));
include(BASE_PATH . '/zFramework/initalize.php');
zFramework\Run::includer('../zFramework/modules', false);
