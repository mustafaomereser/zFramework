<?php
$start    = hrtime(true);
$memStart = memory_get_usage();

include 'index.php';

$bootTime = (hrtime(true) - $start) / 1e6;
$bootMem  = (memory_get_usage() - $memStart) / 1024;

file_put_contents2(base_path('/analysis/profiling/' . zFramework\Core\Facades\Analyzer\Analyze::$process_id . '.json'), json_encode([
    'boot_time_ms'        => round($bootTime, 2),
    'boot_memory_kb'      => round($bootMem),
    'peak_memory_kb'      => round(memory_get_peak_usage() / 1024),
    'included_files'      => count(get_included_files()),
    'included_files_list' => zFramework\Run::$included,
    'php_version'         => PHP_VERSION,
    'opcache_enabled'     => function_exists('opcache_get_status') ? (opcache_get_status()['opcache_enabled'] ?? false) : false,
    'sapi'                => PHP_SAPI,
], JSON_PRETTY_PRINT), FILE_APPEND);
