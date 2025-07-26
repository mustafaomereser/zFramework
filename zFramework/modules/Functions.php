<?php

// Create new database connection string
function MySQLcreateDatabase($host = "localhost", $dbname = "dbname", $user = "root", $pass = null, $name = null)
{
    return $GLOBALS['databases']['connections'][$name ? $name : $dbname] = ["mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass];
}

function e($value, $emptycheck = false)
{
    return strlen($value) ? htmlspecialchars($value) : ($emptycheck ? '-' : null);
}

// Get project base path.
function base_path($url = null)
{
    return BASE_PATH . ($url ? "/$url" : null);
}

// Get project real public dir.
function public_dir($url = null)
{
    return PUBLIC_DIR . @$url;
}

// Get project's public path
function public_path($url = null)
{
    return base_path(zFramework\Core\Facades\Config::get('app.public')) . $url;
}

// Get Run's server's host.
function host()
{
    $protocol = (isset($_SERVER['HTTPS']) ? "https" : "http") . "://";
    $port = $_SERVER['SERVER_PORT'];

    $dont_show_port = [80, 443];
    return $protocol . $_SERVER['SERVER_NAME'] . (!empty($port) && !in_array($port, $dont_show_port) ? ":$port" : null);
}

// no cache public asset
function asset($value)
{
    $value = str_replace("//", "/", "/$value");
    return host() . $value . "?v=" . filemtime(public_dir($value));
}

// file_put_content force make dirs.
function file_put_contents2($file_name, $content, $flags = 0)
{
    @mkdir(dirname($file_name), 0777, true);
    return file_put_contents($file_name, $content, $flags);
}


// Redirect to url what are you want.
function redirect($url = "/")
{
    die(header("Location: $url"));
}

// Back from current to previous page.
function back($add = null)
{
    return redirect(($_SERVER['HTTP_REFERER'] ?? '/') . $add);
}

// Where run it project's dirname.
function script_name()
{
    $script_name = dirname($_SERVER['SCRIPT_NAME']);
    if ($script_name == '\\' || $script_name == '/') return null;
    return strlen($script_name) ? $script_name : null;
}

// Get Current URI
function uri()
{
    $uri = str_replace((script_name() ?? ''), '', $_SERVER['REQUEST_URI']);
    if (!strlen($uri)) $add = '/';
    return ($uri . @$add);
}

function globals($name, $value = NULL)
{
    if ($value === NULL) return @$GLOBALS[$name];
    return @$GLOBALS[$name] = $value;
}

// Get Current Request Method.
function method()
{
    return strtoupper($_POST['_method'] ?? $_SERVER['REQUEST_METHOD']);
}

// Show method with ready input
function inputMethod($method = "GET")
{
    return '<input type="hidden" name="_method" value="' . strtoupper($method) . '" />';
}

// Helper methods: start
// Get Csrf with ready input
function csrf()
{
    return zFramework\Core\Csrf::csrf();
}

// Shortcut view method
function view()
{
    return call_user_func_array([zFramework\Core\View::class, 'view'], func_get_args());
}

// Shortcut Route::find method
function route()
{
    return call_user_func_array([zFramework\Core\Route::class, 'find'], func_get_args());
}

// Shortcut Config::get method
function config()
{
    return call_user_func_array([zFramework\Core\Facades\Config::class, 'get'], func_get_args());
}

// Shortcut Lang::get method
function _l()
{
    return call_user_func_array([zFramework\Core\Facades\Lang::class, 'get'], func_get_args());
}
// Helper methods: end

// Get Client IP address
function ip()
{
    return ($_SERVER['HTTP_CLIENT_IP'] ?? ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR']));
}

// Http::abort shorcut
function abort()
{
    return call_user_func_array([zFramework\Core\Helpers\Http::class, 'abort'], func_get_args());
}

// Get current uri but with parse
function getQuery($adds = [], $except = [], $string = true)
{
    parse_str($_SERVER['QUERY_STRING'], $output);

    //
    foreach ($except as $key => $unset)
        if (is_array($unset)) foreach ($unset as $f) unset($output[$key][$f]);
        else unset($output[$unset]);

    foreach ($adds as $key => $add) $output[$key] = $add;
    //

    if ($string) return "?" . http_build_query($output);

    return $output;
}

// Current Request query.
function request($name = null, $val = NULL)
{
    if ($val === NULL) return $name ? ($_REQUEST[$name] ?? false) : $_REQUEST;
    return $_REQUEST[$name] = $val;
}

// Find file.
function findFile($file, $ext = null, $path = null)
{
    if ($path) $path .= "/";


    try {
        @$dirTree = scan_dir(base_path($path));
    } catch (\Throwable $e) {
    }

    $dirs = [];
    foreach ($dirTree ?? [] as $name) {
        $full_path = $path . $name;
        if (is_file(base_path($full_path)) && strstr($name, "$file.$ext")) return $full_path;
        else $dirs[] = $full_path;
    }

    foreach ($dirs as $dir) {
        $result = findFile($file, $ext, $dir);
        if (is_string($result)) return $result;
    }
}

// Get Client's browser details.
function getBrowser()
{
    $u_agent  = $_SERVER['HTTP_USER_AGENT'];
    $bname    = 'Unknown';
    $platform = 'Unknown';
    $version  = "";

    if (preg_match('/linux/i', $u_agent)) $platform = 'linux';
    elseif (preg_match('/macintosh|mac os x/i', $u_agent)) $platform = 'mac';
    elseif (preg_match('/windows|win32/i', $u_agent)) $platform = 'windows';

    if (preg_match('/MSIE/i', $u_agent) && !preg_match('/OPR/i', $u_agent)) {
        $bname = 'Internet Explorer';
        $ub = "MSIE";
    } elseif (preg_match('/Firefox/i', $u_agent)) {
        $bname = 'Mozilla Firefox';
        $ub = "Firefox";
    } elseif (preg_match('/OPR/i', $u_agent)) {
        $bname = 'Opera';
        $ub = "Opera";
    } elseif (preg_match('/Netscape/i', $u_agent)) {
        $bname = 'Netscape';
        $ub = "Netscape";
    } elseif (preg_match('/Edg/i', $u_agent)) {
        $bname = 'Edge';
        $ub = "Edge";
    } elseif (preg_match('/Trident/i', $u_agent)) {
        $bname = 'Internet Explorer';
        $ub = "MSIE";
    } elseif (preg_match('/Chrome/i', $u_agent) && !preg_match('/Edg/i', $u_agent)) {
        $bname = 'Google Chrome';
        $ub = "Chrome";
    } elseif (preg_match('/Safari/i', $u_agent) && !preg_match('/Edg/i', $u_agent)) {
        $bname = 'Apple Safari';
        $ub = "Safari";
    }

    // finally get the correct version number
    $known   = array('Version', $ub, 'other');
    $pattern = '#(?<browser>' . join('|', $known) .
        ')[/ ]+(?<version>[0-9.|a-zA-Z.]*)#';
    preg_match_all($pattern, $u_agent, $matches);

    $i = count($matches['browser']);
    if ($i != 1) {
        if (strripos($u_agent, "Version") < strripos($u_agent, $ub)) $version = $matches['version'][0];
        else $version = @$matches['version'][1];
    } else {
        $version = $matches['version'][0];
    }

    return [
        'userAgent' => $u_agent,
        'name'      => $bname,
        'ub'        => $ub,
        'version'   => $version ?? '?',
        'platform'  => $platform,
        'pattern'   => $pattern
    ];
}

// for Easy call models
function model($model)
{
    return new $model;
}

function scan_dir($dir)
{
    return array_values(array_diff(scandir($dir), ['.', '..']));
}
