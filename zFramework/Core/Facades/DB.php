<?php

namespace zFramework\Core\Facades;

use ReflectionClass;
use zFramework\Core\GlobalCache;
use zFramework\Core\Facades\DB\Analyzer\DbCollector;
use zFramework\Core\Helpers\Date;
use zFramework\Core\Traits\DB\OrMethods;
use zFramework\Core\Traits\DB\RelationShips;

#[\AllowDynamicProperties]
class DB
{
    use RelationShips;
    use OrMethods;

    public $db;

    /**
     * Connection name the caller asked for, defined or not. Only used for errors.
     */
    private ?string $requestedDb = null;
    public $dbname;
    public $connection = null;
    private $driver;
    private $builder;
    private $sqlDebug  = false;
    private $wherePrev = 'AND';
    public $ignoreAnalyze = false;
    public $cache_dir;

    /**
     * Operators that carry their own null semantics, so they take no bound value.
     */
    private const NULL_OPERATORS = ['IS NULL', 'IS NOT NULL'];

    /**
     * scheme.json mtime behind each in-process scheme copy, keyed by database name.
     * Kept out of $GLOBALS['DB'] because that array is read directly elsewhere.
     */
    private static array $schemeMtimes = [];

    /**
     * Connections whose scheme has already been validated during this request.
     * Cleared by Run::resetState() so a worker re-checks on the next one.
     */
    public static array $schemeChecked = [];

    /**
     * Clear what belongs to a single request. Called between requests by a
     * long-running worker; under FPM the process ends instead.
     *
     * Clearing $schemeChecked makes the next request re-validate the table
     * scheme, so a migration is noticed by a worker that is already running.
     *
     * The rollback covers a request that ended between beginTransaction() and
     * commit(). Connections outlive the request in a worker, so the transaction
     * would too, and the next request would carry on writing inside it.
     *
     * @return void
     */
    public static function flushRequestState(): void
    {
        self::$schemeChecked = [];

        foreach ($GLOBALS['databases']['connections'] ?? [] as $connection) {
            if (!($connection instanceof \PDO)) continue;

            # A connection the server has already dropped throws here rather than
            # answering, and that is not this request's problem to report.
            try {
                if ($connection->inTransaction()) $connection->rollBack();
            } catch (\Throwable) {
            }
        }
    }

    /**
     * Options parameters
     */
    public $table;
    public $originalTable;
    public $buildQuery      = [];
    public $cache           = [];
    public $setClosures     = true;


    /**
     * Initial, Select Database.
     * @param ?string @db
     */
    public function __construct(?string $db = null)
    {
        global $storage_path;
        $this->cache_dir = $storage_path . "/db";

        if (!$db) $db = array_keys($GLOBALS['databases']['connections'])[0] ?? null;

        # Kept even when the name is unknown, so the error can name what was asked for.
        $this->requestedDb = $db;
        if (isset($GLOBALS['databases']['connections'][$db])) $this->db = $db;

        $this->connection();
        $this->reset();
    }

    /**
     * Create database connection or return already current connection.
     * @return object|bool
     */
    public function connection(): object|bool
    {
        if ($this->connection !== null) return $this->connection;

        # The `$this->table &&` guard is deliberate and must stay: an instance may
        # be constructed before its connection exists - multi-tenant setups resolve
        # the tenant and register its connection after the model is created. Such an
        # instance has no table yet, and bailing out here would leave it permanently
        # unusable even once the connection is registered.
        if ($this->table && !isset($GLOBALS['databases']['connections'][$this->db])) return false;
        if (!isset($GLOBALS['databases']['connected'][$this->db])) {
            try {
                $parameters = $GLOBALS['databases']['connections'][$this->db];

                # connections[] is overwritten with the live PDO below, so the dsn and
                # credentials are kept aside - reconnect() needs them to build a new
                # handle after the server drops one.
                $GLOBALS['databases']['dsn'][$this->db] ??= $parameters;

                # Options go to the constructor, not setAttribute(). ATTR_PERSISTENT
                # is decided while the handle is built and setAttribute() returns
                # false for it without saying so.
                $options = [];
                foreach ($parameters['options'] ?? [] as $option) $options[$option[0]] = $option[1];

                $connection = new \PDO($parameters[0], $parameters[1], ($parameters[2] ?? null), $options);
            } catch (\Throwable $err) {
                # 503 + Retry-After rather than an error page, so a load balancer's
                # health check pulls this node out and puts it back once the
                # database answers again.
                \zFramework\Core\Facades\Response::status(503);
                \zFramework\Core\Facades\Response::header('Retry-After', '5');

                # errorHandler() prints the page and returns it, so die() must
                # not be given the return value - that printed it twice.
                errorHandler($err);
                die;
            }

            $new_connection = true;
            $GLOBALS['databases']['connected'][$this->db]['driver'] = $connection->getAttribute(\PDO::ATTR_DRIVER_NAME);
            $GLOBALS['databases']['connections'][$this->db]         = $connection;
        }

        $this->driver = $GLOBALS['databases']['connected'][$this->db]['driver'];

        # One builder per connection, shared by every model using it. The builder
        # holds no state but its owner, and setParent() re-points it just before
        # use - constructing one per model would ask the server for the database
        # name each time.
        $this->builder = $GLOBALS['databases']['builders'][$this->db] ??= (new ("\zFramework\Core\Facades\DB\Drivers\\$this->driver")($this));
        $this->dbname  = $GLOBALS['databases']['connected'][$this->db]['name'];

        if (isset($new_connection)) $this->tables();
        return $this->connection = $GLOBALS['databases']['connections'][$this->db];
    }

    /**
     * Execute sql query.
     * @param string $sql
     * @param array $data
     * @return object
     */
    public function prepare(string $sql, array $data = []): object
    {
        $data = count($data) ? $data : $this->buildQuery['data'] ?? [];
        $queryTime = microtime(true);

        try {
            $e = $this->connection()->prepare($sql);
            $e->execute($data);
        } catch (\PDOException $exception) {
            # Under FPM a dropped connection is somebody else's problem: the next
            # request opens a new one. A long-running worker keeps the same handle
            # for hours, and MySQL closes it after wait_timeout - so the first query
            # after an idle spell fails with "server has gone away". Reconnect and
            # run it once more; anything else is a real error and is re-thrown.
            if (!$this->connectionLost($exception)) throw $exception;

            $this->reconnect();
            $e = $this->connection()->prepare($sql);
            $e->execute($data);
        }

        $queryTime = microtime(true) - $queryTime;
        # SELECT only - EXPLAIN on a write costs a round-trip and suggests
        # nothing - and never without app.debug, since analysing a query means
        # running it twice. framework.profiling.queryAnalyze is true, false, or a
        # sampling rate.
        if (!$this->ignoreAnalyze && config('app.debug') && DbCollector::isSelect($sql)) {
            $configured = Config::framework('profiling.queryAnalyze');

            # Older applications keep this in app.php. Config::get() answers a
            # missing key with the level above it, so guard against the whole
            # app.php array arriving here and casting to 1.
            if ($configured === null) {
                $legacy     = config('app.analyze');
                $configured = is_scalar($legacy) ? $legacy : false;
            }

            $rate = (float) $configured;
            if ($rate > 0 && ($rate >= 1 || (mt_rand() / mt_getrandmax()) < $rate)) DbCollector::analyze($this, $sql, $data, $queryTime);
        }
        $this->reset();
        return $e;
    }

    /**
     * Select table.
     * @param string $table
     * @return self|bool
     */
    public function table(string $table): self|bool
    {
        if (!$this->dbname) return false;
        if (!in_array($table, array_keys($this->tables()['TABLE_COLUMNS'] ?? []))) throw new \Exception("`$table` is not there in database.", 1001);
        $this->table         = $table;
        $this->originalTable = $table;
        return $this;
    }

    /**
     * Cache key holding a connection's table scheme in APCu.
     * @return string
     */
    private function schemeCacheKey(): string
    {
        return "db.scheme.{$this->db}.{$this->dbname}";
    }

    /**
     * Is this exception a dropped connection rather than a bad query?
     *
     * @param \PDOException $exception
     * @return bool
     */
    private function connectionLost(\PDOException $exception): bool
    {
        # 2006 MySQL server has gone away, 2013 lost connection during query.
        if (in_array((int) ($exception->errorInfo[1] ?? 0), [2006, 2013], true)) return true;

        $message = strtolower($exception->getMessage());
        foreach (['server has gone away', 'lost connection', 'broken pipe', 'no connection to the server', 'connection was killed'] as $needle)
            if (strstr($message, $needle)) return true;

        return false;
    }

    /**
     * Drop the connection so the next connection() call opens a fresh one.
     *
     * The builder is dropped with it: it was constructed against the dead handle
     * and cached per connection.
     *
     * @return void
     */
    private function reconnect(): void
    {
        unset(
            $GLOBALS['databases']['connected'][$this->db],
            $GLOBALS['databases']['builders'][$this->db]
        );

        # connections[] holds the live PDO once connected; clearing it makes
        # connection() rebuild from the original dsn/credentials.
        if (isset($GLOBALS['databases']['connections'][$this->db]) && $GLOBALS['databases']['connections'][$this->db] instanceof \PDO)
            $GLOBALS['databases']['connections'][$this->db] = $GLOBALS['databases']['dsn'][$this->db] ?? $GLOBALS['databases']['connections'][$this->db];

        $this->connection = null;
        $this->builder    = null;
    }

    /**
     * Make sure a builder exists, or say why it does not.
     *
     * connection() returns false for a name that is not in
     * database/connections.php. Without this the missing builder would surface
     * three frames later as "Call to a member function build() on null".
     *
     * @return void
     */
    private function requireBuilder(): void
    {
        if (!$this->builder) $this->connection();
        if ($this->builder) return;

        $known = implode(', ', array_keys($GLOBALS['databases']['connections'] ?? [])) ?: 'none';
        throw new \RuntimeException("DB: connection `" . ($this->db ?? $this->requestedDb ?? 'null') . "` is not defined in database/connections.php (defined: $known).");
    }

    /**
     * Forget this connection's table scheme on every layer it is held.
     *
     * Call it after the schema itself changes - migrations do. Dropping only the
     * json file is not enough: within the same process $GLOBALS['DB'] would keep
     * serving the pre-migration scheme, which is exactly what a seeder running
     * right after a migration would read.
     *
     * @return void
     */
    public function forgetScheme(): void
    {
        unset($GLOBALS['DB'][$this->dbname], self::$schemeMtimes[$this->dbname], self::$schemeChecked[$this->dbname]);
        GlobalCache::remove($this->schemeCacheKey());
        @unlink($this->cache_dir . "/" . $this->dbname . "/scheme.json");
    }

    /**
     * Set all tables informations in database.
     *
     * Three layers, cheapest first:
     *   1. $GLOBALS['DB'] - within this request. table() calls tables() on every
     *      call, so without this the scheme was re-read from disk each time.
     *   2. APCu (GlobalCache) - across requests, no disk read, no json_decode.
     *   3. scheme.json on disk, and finally the server itself.
     *
     * The APCu entry carries the scheme file's mtime. Migrations delete that file
     * (see Kernel/Modules/Db.php), and a CLI migration cannot clear the FPM
     * process's APCu, so comparing mtime is what keeps a stale scheme from
     * surviving a migration. filemtime() is a stat call - orders of magnitude
     * cheaper than reading and decoding the file.
     *
     * @return array
     */
    private function tables(): array
    {
        # table() calls tables() on every call, so this runs dozens of times per
        # request. Once the scheme has been validated for this connection, serve it
        # without touching the filesystem again - a stat is cheap locally but not on
        # the network filesystems shared hosting runs on.
        #
        # The check is per request, not per call: $schemeChecked is cleared by
        # resetState(), so a long-running worker re-validates on the next request
        # and a migration is still noticed.
        if (isset($GLOBALS['DB'][$this->dbname], self::$schemeChecked[$this->dbname])) return $GLOBALS['DB'][$this->dbname];

        $path  = $this->cache_dir . "/" . $this->dbname . "/scheme.json";
        $mtime = @filemtime($path) ?: 0;

        # The scheme file's mtime validates every layer, so nothing has to announce
        # that the schema changed: a migration dropping the file, or anyone clearing
        # it by hand, invalidates the in-process copy and the APCu copy alike.
        if (isset($GLOBALS['DB'][$this->dbname]) && (self::$schemeMtimes[$this->dbname] ?? -1) === $mtime) {
            self::$schemeChecked[$this->dbname] = true;
            return $GLOBALS['DB'][$this->dbname];
        }

        $build = function () use ($path) {
            $data = json_decode(@file_get_contents($path), true) ?? false;
            if (!$data) {
                $this->requireBuilder();
                $data = $this->builder->setParent($this)->tables();
                file_put_contents2($path, json_encode($data, JSON_UNESCAPED_UNICODE));
                clearstatcache(true, $path);
            }
            return ['mtime' => @filemtime($path) ?: 0, 'data' => $data];
        };

        $key   = $this->schemeCacheKey();
        $entry = GlobalCache::cache($key, $build);

        if (($entry['mtime'] ?? -1) !== $mtime) {
            GlobalCache::remove($key);
            $entry = GlobalCache::cache($key, $build);
        }

        self::$schemeMtimes[$this->dbname]  = $entry['mtime'] ?? 0;
        self::$schemeChecked[$this->dbname] = true;
        $GLOBALS['DB'][$this->dbname]      = $entry['data'];

        return $entry['data'];
    }

    /**
     * Get primary key.
     * @return string|null
     */
    protected function getPrimary(): string|null
    {
        if (!$this->table) throw new \Exception('firstly you must select a table for get primary key.');
        return $this->primary ?? @$GLOBALS["DB"][$this->dbname]["TABLE_COLUMNS"][$this->table]['primary'] ?? null;
    }

    #region Columns Controls

    /**
     * Get table columns
     * @return array
     */
    public function columns(): array
    {
        $columns = array_column($GLOBALS["DB"][$this->dbname]["TABLE_COLUMNS"][$this->table]['columns'], 'COLUMN_NAME');
        if (count($this->guard ?? [])) $columns = array_diff($columns, $this->guard);
        return $columns;
    }

    /**
     * Get table column's lengths 
     * @return array
     */
    public function columnsLength(): array
    {
        $columns = [];
        foreach ($GLOBALS["DB"][$this->dbname]["TABLE_COLUMNS"][$this->table]['columns'] as $column) $columns[$column['COLUMN_NAME']] = $column['CHARACTER_MAXIMUM_LENGTH'] ?? 65535;
        return $columns;
    }

    /**
     * compare data and Columns max length
     * @param array $data
     * @return array
     */
    public function compareColumnsLength(array $data): array
    {
        $errors    = [];
        $lengthies = $this->columnsLength();
        foreach ($data as $key => $value) {
            $length = strlen($value);
            if ($length > $lengthies[$key]) $errors[$key] = [
                'length' => $length,
                'excess' => $length - $lengthies[$key],
                'max'    => $lengthies[$key],
            ];
        }

        return $errors;
    }

    #endregion


    #region Preparing
    /**
     * Observer trigger on CRUD methods.
     * @param string $name
     * @param array $args
     * @return mixed
     */
    private function trigger(string $name, mixed $args = []): mixed
    {
        if (!isset($this->observe)) return false;
        return call_user_func_array([new ($this->observe), 'router'], [$name, $args]);
    }

    /**
     * Reset build.
     * @return self
     */
    private function resetBuild(): self
    {
        $this->cache['buildQuery'] = $this->buildQuery;
        $this->buildQuery = [
            'select'       => [],
            'join'         => [],
            'where'        => [],
            'orderBy'      => [],
            'groupBy'      => [],
            'limit'        => [],
            'having'       => [],
            'sets'         => "",
            'realOrder'    => null,
            'fetchType'    => \PDO::FETCH_ASSOC
        ];
        return $this;
    }

    /**
     * Model's relatives.
     * @return self
     */
    private function closures(): self
    {
        if (!$this->table || isset($GLOBALS['model-closures'][$this->db][$this->table])) return $this;

        $closures = [];
        foreach ((new ReflectionClass($this))->getMethods() as $closure) if (strstr($closure->class, 'Models') && !in_array($closure->name, $this->not_closures)) $closures[] = $closure->name;
        $GLOBALS['model-closures'][$this->db][$this->table] = $closures;
        return $this;
    }

    /**
     * Add Closure on/off.
     * @return self
     */
    public function closureMode(bool $mode = true): self
    {
        $this->setClosures = $mode;
        return $this;
    }

    /**
     * Set Closures for rows.
     * @return array
     */
    public function setClosures(array $rows): array
    {
        $primary_key = $this->getPrimary();
        foreach ($rows as $key => $row) {
            foreach ($GLOBALS['model-closures'][$this->db][$this->table] as $closure) $rows[$key][$closure] = fn(...$args) => $this->{$closure}(...array_merge($args, [$row]));

            if (isset($row[$primary_key])) {
                $rows[$key]['update'] = fn($sets) => $this->where($primary_key, $row[$primary_key])->update($sets);
                $rows[$key]['delete'] = fn() => $this->where($primary_key, $row[$primary_key])->delete();
            }
        }
        return $rows;
    }

    /**
     * PDO Fetch Type.
     * @param null|string $type
     * @return self
     */
    public function fetchType(null|string $type = null): self
    {
        # 'lazy' is gone: get() is the only reader of this and fetchAll() rejects
        # PDO::FETCH_LAZY outright, so asking for it could only ever have thrown.
        $this->buildQuery['fetchType'] = ['unique' => \PDO::FETCH_UNIQUE, 'keypair' => \PDO::FETCH_KEY_PAIR][$type] ?? \PDO::FETCH_ASSOC;
        return $this;
    }

    /**
     * Begin query for models.
     * this is empty
     * @return mixed
     */
    public function beginQuery()
    {
        return $this;
    }

    /**
     * Reset all data.
     * @return self
     */
    public function reset(): self
    {
        $this->resetBuild();
        $this->closures();
        if (method_exists($this, 'beginQuery')) $this->beginQuery();
        return $this;
    }

    /**
     * Emre UZUN was here.
     * Added hash for unique key.
     * @param string $key
     * @param null|int $level
     * @return string
     */
    public function hashedKey(string $key, null|int $level = null): string
    {
        $key = str_replace([".", '(', ')', ',', '"', "'", '`', ' '], "_", $key) . ($level ?: null);
        if (isset($this->buildQuery['data'][$key])) return $this->hashedKey($key, $level + 1);
        return $key;
    }
    #endregion

    #region BUILD QUERIES
    /**
     * Set Select
     * @param array|string $select
     * @return self
     */
    public function select($select): self
    {
        $this->buildQuery['select'] = $select;
        return $this;
    }

    /**
     * add a join
     * @param string $type
     * @param string $model
     * @param string $on
     * @return self
     */
    public function join(string $type, string $model, string $on = ""): self
    {
        $this->buildQuery['join'][] = [$type, $model, $on];
        return $this;
    }

    /**
     * add a "AND" where
     * @return self
     */
    public function where(): self
    {
        $this->wherePrev = 'AND';
        return self::addWhereOrHaving(func_get_args());
    }

    /**
     * add a "OR" where
     * @return self
     */
    public function whereOr(): self
    {
        $this->wherePrev = 'OR';
        return self::addWhereOrHaving(func_get_args());
    }

    /**
     * add a "AND NOT" where â€” negates the condition.
     * whereNot('status', 'active')          â†’ WHERE status != 'active'
     * whereNot('name', 'LIKE', '%test%')    â†’ WHERE name NOT LIKE '%test%'
     * @return self
     */
    public function whereNot(): self
    {
        $this->wherePrev = 'AND';
        return self::addWhereOrHaving(self::negateArgs(func_get_args()));
    }

    /**
     * add a "OR NOT" where â€” negates the condition with OR connector.
     * @return self
     */
    public function whereOrNot(): self
    {
        $this->wherePrev = 'OR';
        return self::addWhereOrHaving(self::negateArgs(func_get_args()));
    }

    /**
     * Negate where/having arguments.
     * 2-arg: ['key', value]          â†’ ['key', '!=', value]
     * 3-arg: ['key', 'op', value]    â†’ ['key', 'NOT op', value]
     * @param array $args
     * @return array
     */
    private static function negateArgs(array $args): array
    {
        if (count($args) === 2) return [$args[0], '!=', $args[1]];
        if (count($args) >= 3) return [$args[0], 'NOT ' . $args[1], $args[2]];
        return $args;
    }

    /**
     * Add where or having.
     * @param array $parameters
     * @return self
     */
    private function addWhereOrHaving(array $parameters, string $addtype = 'where'): self
    {
        if (gettype($parameters[0]) == 'array') {
            $type    = 'group';
            $queries = [];
            foreach ($parameters[0] as $query) {
                $prepare = $this->prepareWhere($query);
                $queries[] = [
                    'key'      => $prepare['key'],
                    'operator' => $prepare['operator'],
                    'value'    => $prepare['value'],
                    'prev'     => $prepare['prev']
                ];
            }
        } else {
            $type    = 'row';
            $prepare = $this->prepareWhere($parameters);
            $queries = [
                [
                    'key'      => $prepare['key'],
                    'operator' => $prepare['operator'],
                    'value'    => $prepare['value'],
                    'prev'     => $prepare['prev']
                ]
            ];
        }

        $this->buildQuery[$addtype][] = [
            'type'     => $type,
            'queries'  => $queries
        ];

        return $this;
    }

    /**
     * Append data to buildQuery.
     * @param string $key
     * @param mixed $value
     */
    private function appendData(string $key, mixed $value): void
    {
        switch (gettype($value)) {
            case 'object':
                $value = json_encode((array) $value, JSON_UNESCAPED_UNICODE);
                break;
            case 'array':
                $value = json_encode($value, JSON_UNESCAPED_UNICODE);
                break;
        }

        $this->buildQuery['data'][$key] = $value;
    }

    /**
     * Where In sql build.
     * @param string $column
     * @param array $in
     * @param string $prev
     * @return self
     */
    public function whereIn(string $column, array $in = [], string $prev = "AND"): self
    {
        $hashed_keys = [];
        foreach ($in as $value) {
            $hashed_key    = $this->hashedKey($column);
            $hashed_keys[] = $hashed_key;
            $this->appendData($hashed_key, $value);
        }

        $this->buildQuery['where'][] = [
            'type'     => 'row',
            'queries'  => [
                [
                    'raw'      => true,
                    'key'      => $column,
                    'operator' => 'IN',
                    'value'    => '(:' . implode(', :', $hashed_keys) . ')',
                    'prev'     => $prev
                ]
            ]
        ];

        return $this;
    }

    /**
     * Where MOT In sql build.
     * @param string $column
     * @param array $in
     * @param string $prev
     * @return self
     */
    public function whereNotIn(string $column, array $in = [], string $prev = "AND"): self
    {
        $hashed_keys = [];
        foreach ($in as $value) {
            $hashed_key    = $this->hashedKey($column);
            $hashed_keys[] = $hashed_key;
            $this->appendData($hashed_key, $value);
        }

        $this->buildQuery['where'][] = [
            'type'     => 'row',
            'queries'  => [
                [
                    'raw'      => true,
                    'key'      => $column,
                    'operator' => 'NOT IN',
                    'value'    => '(:' . implode(', :', $hashed_keys) . ')',
                    'prev'     => $prev
                ]
            ]
        ];

        return $this;
    }

    /**
     * Where between sql build.
     * @param string $column
     * @param mixed $start
     * @param mixed $stop
     * @param string $prev
     * @return self
     */
    public function whereBetween(string $column, $start, $stop, string $prev = 'AND'): self
    {
        $uniqid = uniqid();

        $this->buildQuery['where'][] = [
            'type'     => 'row',
            'queries'  => [
                [
                    'raw'      => true,
                    'key'      => $column,
                    'operator' => 'BETWEEN',
                    'value'    => ":start_$uniqid AND :stop_$uniqid",
                    'prev'     => $prev
                ]
            ]
        ];

        $this->buildQuery['data']["start_$uniqid"] = $start;
        $this->buildQuery['data']["stop_$uniqid"]  = $stop;

        return $this;
    }

    /**
     * Where NOT between sql build.
     * @param string $column
     * @param mixed $start
     * @param mixed $stop
     * @param string $prev
     * @return self
     */
    public function whereNotBetween(string $column, $start, $stop, string $prev = 'AND'): self
    {
        return $this->whereBetween("$column NOT", $start, $stop, $prev);
    }

    /**
     * Raw where query sql build.
     * @param string $sql
     * @param array $data
     * @param string $prev
     * @return self
     */
    public function whereRaw(string $sql, array $data = [], string $prev = "AND"): self
    {
        $this->buildQuery['where'][] = [
            'type'     => 'row',
            'queries'  => [
                [
                    'raw'      => true,
                    'key'      => null,
                    'operator' => $sql,
                    'value'    => null,
                    'prev'     => $prev
                ]
            ]
        ];

        foreach ($data as $key => $value) $this->appendData($key, $value);

        return $this;
    }

    /**
     * Prepare where
     * @param array $data
     * @return array
     */
    private function prepareWhere(array $data): array
    {
        $key      = $data[0];
        $prev     = $this->wherePrev;
        $operator = "=";
        $value    = null;

        $count    = count($data);

        if ($count == 2) {
            $value = $data[1];
        } elseif ($count >= 3) {
            $operator = $data[1];
            $value    = $data[2];
        }

        # A null value is only meaningful next to a null-aware operator. Everywhere else
        # the builder skips both the bind and the placeholder, emitting "WHERE key = LIMIT 1"
        # (SQLSTATE 42000 / 1064) far away from the code that leaked the null.
        if ($value === null && !in_array(strtoupper(trim($operator)), self::NULL_OPERATORS, true))
            throw new \InvalidArgumentException("DB: `$key` was given a null value with the `$operator` operator. For a null check write where('$key', 'IS NULL', null); otherwise fix the null leaking into the query.");

        return compact('key', 'operator', 'value', 'prev');
    }

    /**
     * add a "AND" having
     * @return self
     */
    public function having(): self
    {
        $this->wherePrev = 'AND';
        return self::addWhereOrHaving(func_get_args(), 'having');
    }

    /**
     * add a "OR" having
     * @return self
     */
    public function havingOr(): self
    {
        $this->wherePrev = 'OR';
        return self::addWhereOrHaving(func_get_args(), 'having');
    }

    /**
     * add a "AND NOT" having
     * havingNot('count', 5)              â†’ HAVING count != 5
     * havingNot('name', 'LIKE', '%x%')   â†’ HAVING name NOT LIKE '%x%'
     * @return self
     */
    public function havingNot(): self
    {
        $this->wherePrev = 'AND';
        return self::addWhereOrHaving(self::negateArgs(func_get_args()), 'having');
    }

    /**
     * add a "OR NOT" having
     * @return self
     */
    public function havingOrNot(): self
    {
        $this->wherePrev = 'OR';
        return self::addWhereOrHaving(self::negateArgs(func_get_args()), 'having');
    }

    /**
     * Set Order By
     * @param array $data
     * @return self
     */
    public function orderBy(array $data = []): self
    {
        $this->buildQuery['orderBy'] = $data;
        return $this;
    }

    /**
     * Set Group By
     * @param array $data
     * @return self
     */
    public function groupBy(array $data = []): self
    {
        $this->buildQuery['groupBy'] = $data;
        return $this;
    }

    /**
     * Set limit
     * @param int $startPoint
     * @param mixed $getCount
     * @return self
     */
    public function limit(int $startPoint = 0, $getCount = null): self
    {
        $this->buildQuery['limit'] = [$startPoint, $getCount];
        return $this;
    }

    /**
     * Select the row's position in the table's default order as an extra column.
     *
     * Once a list is sorted by the user, the per-page counter ($list['start']++)
     * says nothing about where a row actually sits. This adds an index-only
     * correlated count over the primary key:
     *
     *   (SELECT COUNT(*) FROM posts zf_ro WHERE zf_ro.id >= posts.id) AS real_order
     *
     * Only the primary key index is read, never the table data, so on a page of
     * 20 rows the cost stays in the millisecond range.
     *
     * The subquery repeats no WHERE clause on purpose: the number is the row's
     * position in the whole table, not within the current filter. Ranking inside
     * a filter would mean duplicating every condition into the subquery.
     *
     * Example:
     *   $list = (new Posts)->withRealOrder()->paginate(20);
     *   // in the view: $item['real_order']
     *
     * @param string $as         column name to read in the view
     * @param string $direction  DESC (default, matching "newest first") or ASC
     * @return self
     */
    public function withRealOrder(string $as = 'real_order', string $direction = 'DESC'): self
    {
        if (!$primary = $this->getPrimary()) throw new \Exception("withRealOrder() needs a primary key on `{$this->table}`.");

        $comparison = strtoupper(trim($direction)) === 'ASC' ? '<=' : '>=';
        $this->buildQuery['realOrder'] = "(SELECT COUNT(*) FROM {$this->table} zf_ro WHERE zf_ro.{$primary} {$comparison} {$this->table}.{$primary}) AS {$as}";

        return $this;
    }

    #endregion

    #region CRUD Proccesses

    /**
     * get rows with query string
     * @return array
     */
    public function get(): array
    {
        # Read before the call, not inside it: run() ends in reset(), which puts
        # fetchType back to its default, and PHP evaluates $this->run() before the
        # argument next to it.
        $fetchType = $this->buildQuery['fetchType'];
        $rows      = $this->run()->fetchAll($fetchType);

        # Closures and eager loading key onto a row by column name, which only
        # works for FETCH_ASSOC. FETCH_KEY_PAIR returns scalars, and FETCH_UNIQUE
        # moves the primary key out of the row into the array key.
        $keyed = $fetchType === \PDO::FETCH_ASSOC;

        if ($this->setClosures && $keyed) $rows = $this->setClosures($rows);

        # After setClosures, so an eager loaded relation replaces its closure with
        # the value itself - $item['posts'] instead of $item['posts']().
        if ($this->eagerLoad) {
            if ($keyed) $this->loadRelations($rows);
            $this->eagerLoad = [];
        }

        return $rows;
    }

    /**
     * Row count
     * @return int
     */
    public function count(): int
    {
        # Counted by the server. Reading rowCount() off a SELECT would mean
        # fetching every row into PHP first, which is what buffered queries do -
        # expensive on a large table, and worse inside a loop.
        $this->buildQuery['realOrder'] = null;
        $this->buildQuery['orderBy']   = [];

        # A grouped result has to be counted from the outside - COUNT() inside the
        # query would count each group's members rather than the groups.
        if (!empty($this->buildQuery['groupBy']) || !empty($this->buildQuery['having'])) {
            $this->buildQuery['limit'] = [];

            $inner = $this->buildSQL('select');
            $data  = $this->buildQuery['data'] ?? [];
            $this->resetBuild();

            return (int) ($this->prepare("SELECT COUNT(*) as count FROM ({$inner}) as sub", $data)->fetch(\PDO::FETCH_ASSOC)['count'] ?? 0);
        }

        $this->buildQuery['groupBy'] = [];

        # A join can repeat the same row, hence DISTINCT. Without a primary key
        # there is nothing to be distinct about, so count rows instead.
        $primary = $this->getPrimary();
        $column  = $primary ? (!empty($this->buildQuery['join']) ? 'DISTINCT ' : '') . "{$this->table}.{$primary}" : '*';

        return (int) ($this->select("COUNT({$column}) as count")->first()['count'] ?? 0);
    }

    /**
     * get one row in rows
     * @return array
     */
    public function first(): array
    {
        return $this->limit(1)->get()[0] ?? [];
    }

    /**
     * Find row by primary key
     * @param string $value
     * @return array
     */
    public function find(string $value): array
    {
        return $this->where($this->getPrimary(), $value)->first();
    }

    /**
     * Seek
     * @param array $lastrow
     * @return bool
     */
    protected function seek(?array $lastrow = null): bool
    {
        if (!$lastrow) return false;

        $orderBy = count($this->buildQuery['orderBy'])
            ? $this->buildQuery['orderBy']
            : [$this->getPrimary() => 'ASC'];

        $conditions  = [];
        $data        = [];
        $prevColumns = [];

        foreach ($orderBy as $column => $dir) {
            $hashed_key = $this->hashedKey($column);
            $param      = strtoupper($dir) === 'DESC' ? '<' : '>';

            // Lexicographical
            $lexConditions = [];
            foreach ($prevColumns as $prev) {
                $lexConditions[] = "$prev = :$prev";
                $data[$prev]     = $lastrow[$prev];
            }

            $lexConditions[]   = "$column $param :$hashed_key";
            $data[$hashed_key] = $lastrow[$column];

            $conditions[]  = '(' . implode(' AND ', $lexConditions) . ')';
            $prevColumns[] = $hashed_key;
        }

        $seekWhere = implode(' OR ', $conditions);

        $this->whereRaw($seekWhere, $data);

        return true;
    }


    /**
     * paginate
     * @param int $per_page
     * @param string $page_id
     * @return array
     */
    public function paginate(int $per_page = 20, string $page_id = 'page', null|string $cache_id = null): array
    {
        if (!$cache_id) Session::callback(function () {
            unset($_SESSION[$this->db][$this->dbname]['paginate']['cache']);
        });
        else {
            $cache = Session::callback(fn() => $_SESSION[$this->db][$this->dbname]['paginate']['cache'][$cache_id] ?? false);
            if ($cache) $row_count = $cache;
        }

        if (!isset($row_count)) {
            $snapshot = $this->buildQuery;

            # The ranking subquery would run once per counted row for nothing.
            $this->buildQuery['realOrder'] = null;

            if (!empty($this->buildQuery['groupBy']) || !empty($this->buildQuery['having'])) {
                // exists GROUP BY or HAVING subquery
                $this->buildQuery['orderBy'] = [];
                $this->buildQuery['limit']   = [];
                $innerSQL  = $this->buildSQL('select');
                $innerData = $this->buildQuery['data'] ?? [];
                $this->resetBuild();
                $row_count = $this->prepare("SELECT COUNT(*) as count FROM ({$innerSQL}) as sub", $innerData)->fetch(\PDO::FETCH_ASSOC)['count'];
            } else {
                // Normal count
                $this->buildQuery['orderBy'] = [];
                $this->buildQuery['groupBy'] = [];
                $row_count = $this->select("COUNT(" . (!empty($this->buildQuery['join']) ? 'DISTINCT ' : null) . "{$this->table}.{$this->getPrimary()}) as count")->first()['count'];
            }

            $this->buildQuery = $snapshot;

            if ($cache_id) Session::callback(fn() => $_SESSION[$this->db][$this->dbname]['paginate']['cache'][$cache_id] = $row_count);
        }

        $uniqueID         = uniqid();
        $current_page     = (request($page_id) ?? 1);
        $page_count       = ceil($row_count / $per_page);

        if ($current_page > $page_count) $current_page = $page_count;
        elseif ($current_page <= 0) $current_page = 1;

        $start_count = ($per_page * ($current_page - 1));
        if (!$row_count) $start_count = -1;

        @parse_str(@$_SERVER['QUERY_STRING'], $queryString);
        $queryString[$page_id] = "change_page_$uniqueID";
        $url = "?" . http_build_query($queryString);

        # seek test.
        // $items = !$this->seek(Session::get('last-item-test')) ? self::limit($start_count, $per_page)->get() : self::limit($per_page)->get();
        // Session::set('last-item-test', @end(json_decode(json_encode($items, JSON_UNESCAPED_UNICODE), true)));
        #

        $items = self::limit(!($start_count < 0) ? $start_count : 0, $per_page)->get();
        return [
            'items'          => $row_count ? $items : [],
            'item_count'     => $row_count,
            'shown'          => ($start_count + 1) . " / " . (($per_page * $current_page) >= $row_count ? $row_count : ($per_page * $current_page)),
            'start'          => ($start_count + 1),

            'per_page'       => $per_page,
            'page_count'     => $page_count,
            'current_page'   => $current_page,

            'links'          => function ($view = null) use ($page_count, $current_page, $url, $uniqueID) {
                if (!$view) $view = config('app.pagination.default-view');

                $pages = [];
                for ($x = 1; $x <= $page_count; $x++) $pages[$x] = [
                    'type'    => 'page',
                    'page'    => $x,
                    'current' => $x == $current_page,
                    'url'     => str_replace("change_page_$uniqueID", $x, $url)
                ];

                return view($view, compact('pages', 'page_count', 'current_page', 'url', 'uniqueID'));
            }
        ];
    }

    /**
     * Insert a row to database
     * @param array $sets
     * @return array|int
     */
    public function insert(array $sets = [], bool $just_insert = false): array|int
    {
        $this->resetBuild();

        if ($new_sets = $this->trigger('insert', $sets)) $sets = $new_sets;

        $hashed_keys = [];
        foreach ($sets as $key => $value) {
            $hashed_key    = $this->hashedKey($key);
            $hashed_keys[] = $hashed_key;
            $this->appendData($hashed_key, $value);
        }

        $this->buildQuery['sets'] = " (" . implode(', ', array_keys($sets)) . ") VALUES (:" . implode(', :', $hashed_keys) . ") ";
        $insert = $this->run(__FUNCTION__)->rowCount();
        if (!$just_insert && $insert && $primary = $this->getPrimary()) {
            $inserted_row = $this->resetBuild()->where($primary, $this->connection()->lastInsertId())->first() ?? [];
            $this->trigger('inserted', $inserted_row);
        }

        return isset($inserted_row) ? $inserted_row : $insert;
    }

    /**
     * Update row(s) in database
     * @param array $sets
     * @return int
     */
    public function update(array $sets = []): int
    {
        $this->buildQuery['sets'] = " SET ";

        if ($new_sets = $this->trigger('update', $sets)) $sets = $new_sets;

        foreach ($sets as $key => $value) {
            $hashed_key = $this->hashedKey($key);
            $this->appendData($hashed_key, $value);
            $this->buildQuery['sets'] .= "$key = :$hashed_key, ";
        }

        $this->buildQuery['sets'] = rtrim($this->buildQuery['sets'], ', ');
        $update = $this->run(__FUNCTION__)->rowCount();
        if ($update) $this->trigger('updated');

        return $update;
    }

    /**
     * Delete row(s) in database
     * @return int
     */
    public function delete(): int
    {
        $this->trigger('delete');

        if (!isset($this->softDelete)) $delete = $this->run(__FUNCTION__)->rowCount();
        else $delete = $this->update([$this->deleted_at => [
            'date' => Date::timestamp(),
            'bool' => 0
        ][$this->deleted_at_type]]);

        $this->trigger('deleted');
        return $delete;
    }
    #endregion

    #region BUILD & Execute

    /**
     * Debug mode for sql queries
     * @param bool $mode
     * @return self
     */
    public function sqlDebug(bool $mode): self
    {
        $this->sqlDebug = $mode;
        return $this;
    }

    /**
     * Create debug sql
     * @param string $sql
     * @param array $data
     * @return string
     */
    public function debugSQL(string $sql, array $data = []): string
    {
        # Bindings passed in win over the ones on the current query, so a caller
        # can render a statement that is no longer being built.
        $data      = count($data) ? $data : $this->buildQuery['data'] ?? [];
        $debug_sql = $sql;

        foreach ($data as $key => $value) $debug_sql = str_replace(":$key", !$value ? 'null' : $this->connection()->quote($value), $debug_sql);

        return $debug_sql;
    }

    /**
     * Build a sql query for execute.
     * @param string $type
     * @param bool $debug_output
     * @return string
     */
    public function buildSQL(string $type = 'select'): string
    {
        $this->requireBuilder();
        $sql = $this->builder->setParent($this)->build($type);

        if ($this->sqlDebug) {
            $debug_sql   = $this->debugSQL($sql);
            $fingerprint = DbCollector::fingerprint($debug_sql);
            ob_start();
            echo "# $fingerprint " . $this->dbname . " Begin SQL Query:\n";
            var_dump($debug_sql);
            echo "\nAnalyze query: ";
            try {
                var_dump($this->connection()->query("EXPLAIN ANALYZE $debug_sql")->fetchAll(\PDO::FETCH_ASSOC));
            } catch (\Throwable $e) {
                echo "*UNSUPPORTED EXPLAIN ANALYZE*";
            }

            echo "\n#End of SQL Query\n";
            $debug = ob_get_clean();
            file_put_contents2(base_path("/db-debug/" . time()), $debug, FILE_APPEND);
        }

        return $sql;
    }

    /**
     * Run created sql query.
     * @param string $type
     * @return mixed
     */
    public function run(string $type = 'select'): mixed
    {
        return $this->prepare($this->buildSQL($type));
    }
    #endregion

    #region Transaction

    /**
     * Check table is using InnoDB engine.
     * @return bool
     */
    private function checkisInnoDB(): bool
    {
        if (empty($this->table)) throw new \Exception('This table is not defined.');
        if ($GLOBALS["DB"][$this->dbname]["TABLE_ENGINES"][$this->table] == 'InnoDB') return true;
        throw new \Exception('This table is not InnoDB. If you want to use transaction system change store engine to InnoDB.');
    }

    /**
     * Begin transaction.
     * @return self
     */
    public function beginTransaction(): self
    {
        $this->checkisInnoDB();
        $this->connection()->beginTransaction();
        return $this;
    }

    /**
     * Rollback changes.
     * @return self
     */
    public function rollback(): self
    {
        $this->connection()->rollBack();
        return $this;
    }

    /**
     * Save all changes.
     * @return self
     */
    public function commit(): self
    {
        $this->connection()->commit();
        return $this;
    }
    #endregion
}
