<?php

namespace zFramework\Core\Facades;

/**
 * The MongoDB connection - and only the connection. Documents are read and
 * written through models extending Abstracts\MongoModel; this class owns the
 * one Manager the process keeps and the little that is global: the database
 * name, availability, raw commands.
 *
 * Built on the mongodb extension directly (MongoDB\Driver\*), no library in
 * between: the driver already speaks wire protocol, pools connections per
 * process and hands documents back as arrays - a wrapper would only add a
 * layer to every call.
 *
 * Deliberately NOT a DB driver: DB's contract is building SQL (buildSQL,
 * whereRaw, joins, migrations), and none of that has a Mongo meaning.
 * MongoModel offers the same verbs where the meaning matches - where, get,
 * first, insert, update, delete, count - and nothing where it does not.
 *
 * Config, in config/framework.php (or a standalone config/mongo.php):
 *
 *   'mongo' => [
 *       'enabled'  => true,
 *       'uri'      => 'mongodb://127.0.0.1:27017',
 *       'database' => 'app',
 *   ],
 */
class Mongo
{
    /**
     * One Manager per process - the extension multiplexes every request over
     * it and reconnects by itself. Boot state: listed in State.php, never
     * cleared between requests.
     */
    private static ?\MongoDB\Driver\Manager $manager = null;

    /**
     * @return array
     */
    private static function config(): array
    {
        # No cache of its own: Config::framework() already memoises, and a
        # second layer here only meant a second thing clearCache() cannot reach.
        return (array) (Config::framework('mongo') ?? []);
    }

    /**
     * Whether anything here can work: the extension is loaded and the config
     * says enabled. Cheap on the request path - two array reads after the
     * first call, and the extension check never autoloads anything.
     *
     * @return bool
     */
    public static function available(): bool
    {
        return (self::config()['enabled'] ?? false) && extension_loaded('mongodb');
    }

    /**
     * The Manager every model call goes through.
     *
     * @return \MongoDB\Driver\Manager
     */
    public static function manager(): \MongoDB\Driver\Manager
    {
        if (self::$manager !== null) return self::$manager;

        if (!extension_loaded('mongodb')) throw new \RuntimeException('Mongo: the mongodb extension is not loaded (php.ini: extension=mongodb).');
        if (!(self::config()['enabled'] ?? false)) throw new \RuntimeException('Mongo: not enabled - config/framework.php, mongo.enabled.');

        return self::$manager = new \MongoDB\Driver\Manager(self::config()['uri'] ?? 'mongodb://127.0.0.1:27017');
    }

    /**
     * The database models write into unless they name their own.
     *
     * @return string
     */
    public static function database(): string
    {
        return (string) (self::config()['database'] ?? 'app');
    }

    /**
     * Run a raw database command and hand the documents back as arrays -
     * ping, createIndexes, serverStatus, anything the wire accepts.
     *
     * @param array       $command
     * @param string|null $database
     * @return array
     */
    public static function command(array $command, ?string $database = null): array
    {
        $cursor = self::manager()->executeCommand($database ?? self::database(), new \MongoDB\Driver\Command($command));
        $cursor->setTypeMap(['root' => 'array', 'document' => 'array', 'array' => 'array']);

        return $cursor->toArray();
    }
}
