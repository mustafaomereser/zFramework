<?php

/**
 * RoadRunner worker.
 *
 * Boots the application once and then serves requests in a loop, instead of
 * rebuilding everything per request the way PHP-FPM does. Started by RoadRunner
 * itself - see .rr.yaml - normally through `php terminal run roadrunner`.
 *
 * Nothing here is required to run zFramework. Under FPM, shared hosting or the
 * built-in dev server, public_html/index.php remains the entry point and this
 * file is never loaded.
 *
 * Requires:
 *   composer require spiral/roadrunner-http spiral/roadrunner-worker nyholm/psr7
 */

use Nyholm\Psr7\Factory\Psr17Factory;
use Spiral\RoadRunner\Http\PSR7Worker;
use Spiral\RoadRunner\Worker;
use Psr\Http\Message\ServerRequestInterface;
use zFramework\Core\Facades\Response;

ini_set('display_errors', 'stderr');

define('BASE_PATH', dirname(__DIR__, 2));
define('PUBLIC_DIR', BASE_PATH . '/public_html');

require BASE_PATH . '/zFramework/vendor/autoload.php';

if (!class_exists(Worker::class)) {
    fwrite(STDERR, "RoadRunner PHP packages are missing. Install them with:\n");
    fwrite(STDERR, "  composer require spiral/roadrunner-http spiral/roadrunner-worker nyholm/psr7\n");
    exit(1);
}

include BASE_PATH . '/zFramework/bootstrap.php';

# Boot once: modules, providers, view settings, the route table. Everything that
# does not depend on who is asking.
zFramework\Run::boot();

$factory = new Psr17Factory();
$psr     = new PSR7Worker(Worker::create(), $factory, $factory, $factory);

/**
 * Fill the superglobals from the PSR-7 request.
 *
 * The framework reads $_GET/$_POST/$_SERVER directly, as PHP applications do.
 * Rather than rewriting every read, the worker rebuilds those arrays per request
 * so the application sees exactly what it would under FPM.
 */
$populate = function (ServerRequestInterface $request): void {
    $uri = $request->getUri();

    $_GET     = $request->getQueryParams();
    $_POST    = is_array($parsed = $request->getParsedBody()) ? $parsed : [];
    $_COOKIE  = $request->getCookieParams();
    $_FILES   = $request->getUploadedFiles();
    $_REQUEST = $_GET + $_POST;

    $_SERVER = array_merge($_SERVER, $request->getServerParams(), [
        'REQUEST_METHOD'  => $request->getMethod(),
        'REQUEST_URI'     => $uri->getPath() . ($uri->getQuery() ? '?' . $uri->getQuery() : ''),
        'QUERY_STRING'    => $uri->getQuery(),
        'HTTP_HOST'       => $uri->getHost() . ($uri->getPort() ? ':' . $uri->getPort() : ''),
        'HTTPS'           => $uri->getScheme() === 'https' ? 'on' : 'off',
        'SERVER_PROTOCOL' => 'HTTP/' . $request->getProtocolVersion(),
        'SCRIPT_NAME'     => '/index.php',
        'PHP_SELF'        => '/index.php',
    ]);

    foreach ($request->getHeaders() as $name => $values)
        $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $name))] = implode(', ', $values);
};

while ($request = $psr->waitRequest()) {
    try {
        $populate($request);

        ob_start();
        zFramework\Run::handle();
        $body = (string) ob_get_clean();

        # Response::header() collects headers under the CLI SAPI, where PHP's own
        # header() does nothing. Status comes from the same place.
        $response = $factory->createResponse(Response::status());
        foreach (Response::headers() as [$name, $value]) $response = $response->withAddedHeader($name, $value);

        $psr->respond($response->withBody($factory->createStream($body)));
    } catch (\Throwable $e) {
        if (ob_get_level()) @ob_end_clean();
        $psr->getWorker()->error((string) $e);
    } finally {
        # Hand nothing to the next request: identity, session, language, mail
        # recipients, the matched route. See Run::resetState().
        zFramework\Run::resetState();
    }
}
