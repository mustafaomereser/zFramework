<?php
define('PUBLIC_DIR', __DIR__);
define('BASE_PATH', dirname(__DIR__)); # you can move framework location. for example: define('BASE_PATH', dirname(__DIR__) . "/zframework");
include(BASE_PATH . '/zFramework/bootstrap.php');
zFramework\Run::begin();
