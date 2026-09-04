<?php

namespace zFramework\Core\Facades;

/**
 * The MongoDB connections - and only the connections. Documents are read and
 * written through models extending Abstracts\MongoModel; this class owns the
 * Managers the process keeps and the little that is global: database names,
 * availability, raw commands.
 *
 * Connections live in database/mongoconnections.php, the sibling of
 * connections.php and the same shape of thinking: the first entry is the
 * default, a model picks another with `public $connection = 'name'`, and an
 * empty file means Mongo is off.
 *
 *   return [
 *       'mongo' => ['uri' => 'mongodb://127.0.0.1:27017', 'database' => 'app'],
 *   ];
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
 */
class Mongo
{
    /**
     * One Manager per connection entry, per process - the extension
     * multiplexes every request over it and reconnects by itself. Boot state:
     * listed in State.php, never cleared between requests.
     */
    private static array $managers = [];

    /**
     * The connection entries. Read once per process into $GLOBALS['databases'],
     * where the SQL connections already live - and where a test can register
     * one at runtime.
     *
     * @return array
     */
    private static function connections(): array
    {
        return $GLOBALS['databases']['mongo'] ??= (array) (@include(BASE_PATH . '/database/mongoconnections.php') ?: []);
    }

    /**
     * One entry, by name - or the first one when none is named.
     *
     * @param string|null $connection
     * @return array|null
     */
    private static function entry(?string $connection): ?array
    {
        $connections = self::connections();
        if ($connection !== null) return $connections[$connection] ?? null;

        $first = array_key_first($connections);
        return $first === null ? null : $connections[$first];
    }

    /**
     * Whether anything here can work: the extension is loaded and the entry
     * exists. Cheap on the request path - the file is read once per process,
     * and the extension check never autoloads anything.
     *
     * @param string|null $connection
     * @return bool
     */
    public static function available(?string $connection = null): bool
    {
        return extension_loaded('mongodb') && self::entry($connection) !== null;
    }

    /**
     * The Manager every model call goes through.
     *
     * @param string|null $connection
     * @return \MongoDB\Driver\Manager
     */
    public static function manager(?string $connection = null): \MongoDB\Driver\Manager
    {
        $key = $connection ?? (array_key_first(self::connections()) ?? '');
        if (isset(self::$managers[$key])) return self::$managers[$key];

        if (!extension_loaded('mongodb')) throw new \RuntimeException('Mongo: the mongodb extension is not loaded (php.ini: extension=mongodb).');

        $entry = self::entry($connection);
        if ($entry === null) throw new \RuntimeException($connection === null
            ? 'Mongo: no connection configured - database/mongoconnections.php.'
            : "Mongo: no connection `$connection` in database/mongoconnections.php.");

        return self::$managers[$key] = new \MongoDB\Driver\Manager($entry['uri'] ?? 'mongodb://127.0.0.1:27017');
    }

    /**
     * The database a connection's models write into unless they name their own.
     *
     * @param string|null $connection
     * @return string
     */
    public static function database(?string $connection = null): string
    {
        return (string) (self::entry($connection)['database'] ?? 'app');
    }

    /**
     * Run a raw database command and hand the documents back as arrays -
     * ping, createIndexes, serverStatus, anything the wire accepts.
     *
     * @param array       $command
     * @param string|null $database   Defaults to the connection's own.
     * @param string|null $connection
     * @return array
     */
    public static function command(array $command, ?string $database = null, ?string $connection = null): array
    {
        $cursor = self::manager($connection)->executeCommand($database ?? self::database($connection), new \MongoDB\Driver\Command($command));
        $cursor->setTypeMap(['root' => 'array', 'document' => 'array', 'array' => 'array']);

        return $cursor->toArray();
    }
}
