<?php

/**
 * The report as JSON, for a client that asked for it - an XMLHttpRequest, a
 * fetch() with Accept: application/json, an API consumer.
 *
 * Same shape in debug and production; production carries no detail.
 *
 * @param array $report
 * @param bool  $debug
 */
return function (array $report, bool $debug): string {
    if (!$debug) {
        return json_encode([
            'status'  => 500,
            'message' => 'An unexpected error occurred. It has been reported; if it persists, contact the administrator.',
        ], JSON_UNESCAPED_UNICODE);
    }

    $chain = array_map(fn($ex) => [
        'class'   => $ex['class'],
        'message' => $ex['message'],
        'code'    => $ex['code'],
        'file'    => $ex['frames'][0]['file'],
        'line'    => $ex['frames'][0]['line'],
        'trace'   => array_map(fn($f) => [
            'file'     => $f['file'],
            'line'     => $f['line'],
            'function' => $f['function'],
            'kind'     => $f['kind'],
            'args'     => $f['args'],
        ], $ex['frames']),
    ], $report['chain']);

    return json_encode([
        'status'    => 500,
        'exception' => $chain[0],
        'previous'  => array_slice($chain, 1),
        'request'   => ['method' => $report['request']['method'], 'url' => $report['request']['url'], 'route' => $report['route'], 'user' => $report['user']],
        'queries'   => $report['queries'],
        'env'       => $report['env'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_PRETTY_PRINT);
};
