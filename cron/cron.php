<?php

/**
 * Header for a standalone cron script.
 *
 *   <?php
 *   include(__DIR__ . '/cron.php');
 *   ... one job ...
 *
 * Boots config, the global helpers, autoloading and the database. $cron_mode
 * makes bootstrap skip the session setup and the force-https redirect, neither
 * of which means anything without a browser, and routes, providers and modules
 * are never loaded.
 */

$cron_mode = true;
define('BASE_PATH', str_replace('\\', '/', dirname(__DIR__)));
include(BASE_PATH . '/zFramework/bootstrap.php');
zFramework\Run::includer(FRAMEWORK_PATH . '/modules', false);

# Same handler `terminal` installs. Without it config/app.php `error.logging`
# was true and still recorded nothing from a cron script: the throwable went to
# stderr, and a crontab line ending in `>> /dev/null 2>&1` - which is how they
# are usually written - threw that away too. A job nobody watches is the one
# whose failures most need writing down.
zFramework\Run::includer(FRAMEWORK_PATH . '/modules/error_handlers/loader.php');
