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

# Tells the rest of the framework this CLI process serves HTTP and must survive.
# config/app.php's error callback checks it before calling die(), which would
# otherwise take the worker down on the first handled error.
define('ZF_WORKER', true);

# Boot reads $_SERVER in places (helpers building urls, providers). Under CLI it
# holds the worker's own script path, which would make script_name() derive urls
# from "zFramework/Kernel" - so these are assigned, not merged: += would keep
# CLI's values and every generated url would be wrong.
$_SERVER = array_merge($_SERVER, [
    'REQUEST_URI'     => '/',
    'REQUEST_METHOD'  => 'GET',
    'SERVER_NAME'     => 'localhost',
    'HTTP_HOST'       => 'localhost',
    'SERVER_PROTOCOL' => 'HTTP/1.1',
    'SCRIPT_NAME'     => '/index.php',
    'PHP_SELF'        => '/index.php',
    'DOCUMENT_ROOT'   => PUBLIC_DIR,
    'QUERY_STRING'    => '',
    'HTTPS'           => 'off',
]);

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

    $_GET   = $request->getQueryParams();
    $_FILES = $request->getUploadedFiles();

    # PHP url-decodes cookie names when it populates $_COOKIE itself; RoadRunner
    # hands them over raw. Without this a name containing an encoded character -
    # which Crypter-derived names routinely do - is stored as "a%2Fb" while the
    # framework looks up "a/b", so auth cookies vanish on the next request.
    #
    # rawurldecode, not urldecode: the latter also turns "+" into a space. Values
    # here are base64, where "+" is a real character, and RoadRunner has already
    # decoded the value - so urldecode would corrupt every "+" in a password hash
    # and the login would silently fail to stick. rawurldecode is correct whether
    # the input arrives encoded or not.
    $_COOKIE = [];
    foreach ($request->getCookieParams() as $name => $value) $_COOKIE[rawurldecode((string) $name)] = rawurldecode((string) $value);

    $parsed = $request->getParsedBody();
    $_POST  = is_array($parsed) ? $parsed : [];

    # RoadRunner only parses the body into getParsedBody() for form content types;
    # a raw body (json, or a form the server did not recognise) arrives unparsed
    # and $_POST would be empty - which reads as a missing CSRF token and answers
    # 406 to every POST.
    if (!count($_POST) && in_array(strtoupper($request->getMethod()), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        $raw  = (string) $request->getBody();
        $type = strtolower($request->getHeaderLine('Content-Type'));

        if (strlen($raw)) {
            if (strstr($type, 'application/json')) $_POST = (array) json_decode($raw, true);
            else parse_str($raw, $_POST);
        }
    }

    $_REQUEST = $_POST + $_GET;

    $host = $uri->getHost() . ($uri->getPort() ? ':' . $uri->getPort() : '');

    $_SERVER = array_merge($_SERVER, $request->getServerParams());

    foreach ($request->getHeaders() as $name => $values)
        $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $name))] = implode(', ', $values);

    # Assigned last so nothing from the CLI environment or RoadRunner's own server
    # params can win. SCRIPT_NAME especially: script_name() takes its dirname to
    # build urls, and the worker's path there would corrupt every one of them.
    $_SERVER = array_merge($_SERVER, [
        'REQUEST_METHOD'  => $request->getMethod(),
        'REQUEST_URI'     => $uri->getPath() . ($uri->getQuery() ? '?' . $uri->getQuery() : ''),
        'QUERY_STRING'    => $uri->getQuery(),
        'HTTP_HOST'       => $host,
        'SERVER_NAME'     => $uri->getHost(),
        'SERVER_PORT'     => (string) ($uri->getPort() ?: ($uri->getScheme() === 'https' ? 443 : 80)),
        'HTTPS'           => $uri->getScheme() === 'https' ? 'on' : 'off',
        'SERVER_PROTOCOL' => 'HTTP/' . $request->getProtocolVersion(),
        'SCRIPT_NAME'     => '/index.php',
        'PHP_SELF'        => '/index.php',
        'SCRIPT_FILENAME' => PUBLIC_DIR . '/index.php',
        'DOCUMENT_ROOT'   => PUBLIC_DIR,
    ]);
};

while ($request = $psr->waitRequest()) {
    try {
        $populate($request);

        # PHP emits its session cookie through header(), which does nothing under
        # CLI - so the browser never learns the session id and every request would
        # start a brand new session. Adopt the id the client sent, and hand it back
        # on the response below.
        session_id(!empty($_COOKIE[session_name()]) ? $_COOKIE[session_name()] : '');

        # public_html/index.php sends these on every response; the worker is the
        # equivalent entry point, so it does too.
        Response::header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        Response::header('Pragma', 'no-cache');

        ob_start();
        zFramework\Run::handle();
        $body = (string) ob_get_clean();

        # Response::header() collects headers under the CLI SAPI, where PHP's own
        # header() does nothing. Status comes from the same place.
        $response = $factory->createResponse(Response::status());
        foreach (Response::headers() as [$name, $value]) $response = $response->withAddedHeader($name, $value);

        # Send the session cookie ourselves, for the same reason it had to be read
        # manually: PHP cannot emit it from here.
        if (strlen($id = (string) session_id()) && ($_COOKIE[session_name()] ?? null) !== $id)
            $response = $response->withAddedHeader('Set-Cookie', session_name() . '=' . $id . '; Path=/; HttpOnly; SameSite=Lax');

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
