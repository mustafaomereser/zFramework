<?php

namespace zFramework\Core\Facades;

use App\Models\User;
use zFramework\Core\Crypter;

class Auth
{
    /**
     * When you use ::user() method you must fill that and if you again use ::user() get from this parameter.
     */
    static $user     = null;
    static $model;
    /**
     * API mode for api requests. (Cookie not work with apis)
     */
    static $api_mode = false;

    static $database_exists = false;
    /**
     * Column names to match on, when the user model names none.
     */
    private const COLUMNS = ['email' => 'email', 'password' => 'password', 'passwordencode' => 'crypter'];

    /**
     * What the model actually named, resolved on first use.
     */
    static private ?array $columns = null;

    private static function getMode()
    {
        return self::$api_mode ? Session::class : Cookie::class;
    }

    /**
     * Drop everything tied to the request being served.
     *
     * The single most dangerous leak in a long-running worker: $user surviving
     * into the next request means one visitor is served as another.
     *
     * $api_mode belongs to the request too, and was missed here at first. The API
     * middleware sets it, getMode() reads it, and left standing it makes every
     * later request on that worker authenticate through Session instead of
     * Cookie - so one /api call was enough to stop "remember me" working for
     * every web visitor the worker served afterwards.
     *
     * $model, $columns and $database_exists are boot state and stay.
     *
     * @return void
     */
    public static function flushRequestState(): void
    {
        self::$user     = null;
        self::$api_mode = false;
    }

    public static function init()
    {
        # Which connection the user model declares, read from the class rather
        # than an instance: constructing the model opens that connection, and
        # init() runs on every request - paid by every visitor who never asks who
        # they are. Model::__construct() reads $db off the property too, so a
        # model that sets it anywhere later would not work in the first place.
        $database = (new \ReflectionClass(User::class))->getDefaultProperties()['db']
            ?? array_keys($GLOBALS['databases']['connections'] ?? [])[0]
            ?? null;

        self::$database_exists = isset($GLOBALS['databases']['connections'][$database]);

        if (self::$database_exists && !self::check() && $api_token = (self::getMode())::get('auth-stay-in')) self::attempt(['api_token' => $api_token]);
    }

    /**
     * Column names the user model authenticates with.
     *
     * Read off the model, so a model that builds them in its constructor works
     * the same as one that declares them - but only when something actually
     * authenticates, which is the only time they are needed.
     *
     * @return array
     */
    private static function columns(): array
    {
        return self::$columns ??= (array) (self::model()->special_columns ?? self::COLUMNS);
    }

    /**
     * The user model, built the first time something needs the database - a
     * request that never authenticates never connects.
     *
     * @return User
     */
    public static function model(): User
    {
        return self::$model ??= new User;
    }

    /**
     * How long a session token stays valid (seconds).
     */
    private const TOKEN_TTL = 1209600;   # 2 weeks

    /**
     * How long a user row may be served from cache before being re-read (seconds).
     * A role or permission change takes at most this long to be noticed.
     */
    private const USER_TTL = 60;

    /**
     * Token mode replaces the cookie contents once Redis is available.
     *
     * Cookie mode (no Redis): the user's id and password hash are both in the
     * cookie, and every request re-reads the user row to compare them.
     *
     * Token mode: the cookie holds nothing but an unguessable token. The id and
     * the hash live in Redis, the user row is cached for USER_TTL, and logging
     * out - or a stolen session - can be revoked server-side, which cookie mode
     * cannot do.
     *
     * @return bool
     */
    private static function tokenMode(): bool
    {
        return self::redisEnabled() && Redis::available('session');
    }

    /**
     * Whether config asks for Redis at all.
     */
    private static ?bool $redisEnabled = null;

    /**
     * Answered from config, without naming the Redis class: referring to it
     * autoloads Facades/Redis.php on every request that touches Auth, only to
     * be told "disabled". Boot state - config does not change per request.
     *
     * @return bool
     */
    private static function redisEnabled(): bool
    {
        if (self::$redisEnabled !== null) return self::$redisEnabled;

        $config = Config::framework('redis');

        return self::$redisEnabled = is_array($config) && ($config['enabled'] ?? false);
    }

    /**
     * Login from a User model result array.
     * @param array $user
     * @return bool
     */
    public static function login(array $user): bool
    {
        if (!isset($user['id'])) return false;

        if (self::tokenMode()) {
            $token = bin2hex(random_bytes(32));

            # The hash is kept next to the token, server-side. Comparing it on each
            # request preserves "changing the password ends other sessions" without
            # ever handing the hash to the browser.
            Redis::set("auth:$token", [
                'uid' => $user['id'],
                'pwd' => $user[self::columns()['password']] ?? '',
            ], self::TOKEN_TTL, 'session');

            (self::getMode())::set('auth-session', $token);
            return true;
        }

        (self::getMode())::set('auth-password', $user[self::columns()['password']]);
        (self::getMode())::set('auth-token', $user['id']);
        return true;
    }

    /**
     * Login with user's api_token
     * @param string $token
     * @return bool
     */
    public static function token_login(string $token): bool
    {
        return self::login(self::model()->select('id, ' . self::columns()['password'])->where('api_token', $token)->first());
    }

    /**
     * Logout User
     * @return bool
     */
    public static function logout(): bool
    {
        # Killing the token server-side is what makes the session actually end -
        # in cookie mode the browser simply stops presenting its cookie.
        if ($token = (self::getMode())::get('auth-session')) Redis::delete("auth:$token", 'session');

        self::$user = null;
        (self::getMode())::delete('auth-stay-in');
        (self::getMode())::delete('auth-session');
        (self::getMode())::delete('auth-token');
        (self::getMode())::delete('auth-password');
        return true;
    }

    /**
     * Check User logged in
     * @return bool
     */
    public static function check(): bool
    {
        if (self::$database_exists && isset(self::user()['id'])) return true;
        return false;
    }

    /**
     * Get current logged user informations
     * @return array|self|bool
     */
    public static function user()
    {
        if (self::tokenMode()) return self::userFromToken();

        if (!$user_id = (self::getMode())::get('auth-token')) return false;
        if (self::$user == null) self::$user = self::model()->where('id', $user_id)->first(); // ->where('api_token', 'test', 'OR')
        if (!@self::$user['id'] || !hash_equals((string) self::$user[self::columns()['password']], (string) (self::getMode())::get('auth-password'))) return self::logout();
        return self::$user;
    }

    /**
     * Resolve the current user from a session token.
     *
     * Redis is hit first and the database only when the cached row has expired,
     * so a user making 200 requests in a minute costs one SELECT rather than 200.
     *
     * @return array|bool
     */
    private static function userFromToken()
    {
        if (self::$user !== null) return self::$user;
        if (!$token = (self::getMode())::get('auth-session')) return false;

        $session = Redis::get("auth:$token", 'session');
        if (empty($session['uid'])) return false;

        $cacheKey = "auth:user:{$session['uid']}";
        $user     = Redis::get($cacheKey, 'session');

        if (!$user) {
            # closureMode(false): relation closures cannot be serialised, and this
            # row is about to be cached. They are re-attached below either way.
            $user = self::model()->closureMode(false)->where('id', $session['uid'])->first();
            if (@$user['id']) Redis::set($cacheKey, $user, self::USER_TTL, 'session');
        }

        if (!@$user['id']) return self::logout();

        # Password changed since this token was issued -> the session is over.
        if (!hash_equals((string) ($user[self::columns()['password']] ?? ''), (string) ($session['pwd'] ?? ''))) return self::logout();

        return self::$user = self::model()->setClosures([$user])[0];
    }

    /**
     * Drop the cached user row, e.g. right after updating the current user.
     *
     * Without this a change waits out USER_TTL before it is visible. No-op in
     * cookie mode, where every request reads the row anyway.
     *
     * @param string|int|null $id Defaults to the logged in user.
     * @return void
     */
    public static function forgetCache(string|int|null $id = null): void
    {
        $id ??= self::id();
        if ($id) Redis::delete("auth:user:$id", 'session');
        self::$user = null;
    }

    /**
     * Hash a plain password using the configured encode method.
     * @param null|string $plain
     * @return string|bool
     */
    public static function encodePassword(null|string $plain): string|bool
    {
        if (is_null($plain)) return false;
        return match (self::columns()['passwordencode']) {
            'bcrypt' => password_hash($plain, PASSWORD_BCRYPT),
            'md5'    => md5($plain),
            default  => Crypter::encode($plain),
        };
    }

    /**
     * Attempt for login.
     * @param array $fields
     * @param bool $staymein
     * @return bool
     */
    public static function attempt(array $fields = [], bool $staymein = false): bool
    {
        if (self::check()) return false;

        $user  = self::model()->select('id, api_token, ' . self::columns()['password']);
        $plain = $fields[self::columns()['password']] ?? null;
        unset($fields[self::columns()['password']]);
        foreach ($fields as $key => $value) $user->where($key, $value);
        $user = $user->first();

        $hash  = $user[self::columns()['password']] ?? '';
        $valid = self::columns()['passwordencode'] === 'bcrypt' ? password_verify($plain, $hash) : self::encodePassword($plain) === $hash;
        if (!@$user['id'] || ($plain !== null && !$valid)) return false;

        if (@$user['id']) {
            self::login($user);
            if ($staymein) (self::getMode())::set('auth-stay-in', $user['api_token'], time() * 2);
            return true;
        }

        return false;
    }

    /**
     * Get Current logged in user's id
     * @return integer
     */
    public static function id(): int|null
    {
        return (self::user() ?: [])['id'] ?? null;
    }
}
