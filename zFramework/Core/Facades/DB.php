<?php

namespace zFramework\Core\Facades;

use ReflectionClass;
use zFramework\Core\Facades\Analyzer\DbCollector;
use zFramework\Core\Helpers\Date;
use zFramework\Core\Traits\DB\OrMethods;
use zFramework\Core\Traits\DB\RelationShips;

#[\AllowDynamicProperties]
class DB
{
    use RelationShips;
    use OrMethods;

    public $db;
    public $dbname;
    public $connection = null;
    private $driver;
    private $builder;
    private $sqlDebug  = false;
    private $wherePrev = 'AND';
    public $ignoreAnalyze = false;
    public $cache_dir;

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
        if ($this->table && !isset($GLOBALS['databases']['connections'][$this->db])) return false;
        if (!isset($GLOBALS['databases']['connected'][$this->db])) {
            try {
                $parameters = $GLOBALS['databases']['connections'][$this->db];
                $connection = new \PDO($parameters[0], $parameters[1], ($parameters[2] ?? null));
                foreach ($parameters['options'] ?? [] as $option) $connection->setAttribute($option[0], $option[1]);
            } catch (\Throwable $err) {
                die(errorHandler($err));
            }

            $new_connection = true;
            $GLOBALS['databases']['connected'][$this->db]['driver'] = $connection->getAttribute(\PDO::ATTR_DRIVER_NAME);
            $GLOBALS['databases']['connections'][$this->db]         = $connection;
        }

        $this->driver  = $GLOBALS['databases']['connected'][$this->db]['driver'];
        $this->builder = (new ("\zFramework\Core\Facades\DB\Drivers\\$this->driver")($this));
        $this->dbname  = $GLOBALS['databases']['connected'][$this->db]['name'];

        if (isset($new_connection)) $this->tables();
        $this->connection = $GLOBALS['databases']['connections'][$this->db];
        return $this->connection;
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
        $e = $this->connection()->prepare($sql);

        try {
            $e->execute($data);
        } catch (\Throwable $e) {
            $err = [
                '1146' => fn() => throw new \Exception($e->getMessage(), 1001),
            ][(string) $e->errorInfo['1']] ?? false;
            if ($err) return $err();
            throw new \Exception($e->getMessage());
        }

        $queryTime = microtime(true) - $queryTime;
        if (!$this->ignoreAnalyze && config('app.analyze')) DbCollector::analyze($this, $sql, $data, $queryTime);
        $this->reset();
        return $e;
    }

    /**
     * Select table.
     * @param string $table
     * @return self
     */
    public function table(string $table): self
    {
        if (!in_array($table, array_keys($this->tables()['TABLE_COLUMNS'] ?? []))) throw new \Exception("`$table` is not there in database.", 1001);
        $this->table         = $table;
        $this->originalTable = $table;
        return $this;
    }

    /**
     * Set all tables informations in database.
     * @return array
     */
    private function tables(): array
    {
        $data = json_decode(@file_get_contents($this->cache_dir . "/" . $this->dbname . "/scheme.json"), true) ?? false;
        if (!$data) {
            $data = $this->builder->tables();
            file_put_contents2($this->cache_dir . "/" . $this->dbname . "/scheme.json", json_encode($data, JSON_UNESCAPED_UNICODE));
        }
        $GLOBALS['DB'][$this->dbname] = $data;
        return $data;
    }

    /**
     * Get primary key.
     * @return string|null
     */
    private function getPrimary(): string|null
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
    private function trigger(string $name, array $args = []): mixed
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
     * Set Closures for rows
     * @return array
     */
    public function setClosures(array $rows): array
    {
        $primary_key = $this->getPrimary();
        foreach ($rows as $key => $row) {
            foreach ($GLOBALS['model-closures'][$this->db][$this->table] as $closure) $rows[$key][$closure] = fn(...$args) => $this->{$closure}(...array_merge($args, [$row]));

            if (!isset($row[$primary_key])) continue;

            $rows[$key]['update'] = fn($sets) => $this->where($primary_key, $row[$primary_key])->update($sets);
            $rows[$key]['delete'] = fn() => $this->where($primary_key, $row[$primary_key])->delete();
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
        $this->buildQuery['fetchType'] = ['unique' => \PDO::FETCH_UNIQUE, 'lazy' => \PDO::FETCH_LAZY, 'keypair' => \PDO::FETCH_KEY_PAIR][$type] ?? \PDO::FETCH_ASSOC;
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
        return self::addWhere(func_get_args());
    }

    /**
     * add a "OR" where
     * @return self
     */
    public function whereOr(): self
    {
        $this->wherePrev = 'OR';
        return self::addWhere(func_get_args());
    }

    /**
     * Add where item.
     * @param array $parameters
     * @return self
     */
    private function addWhere(array $parameters): self
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

        $this->buildQuery['where'][] = [
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

        return compact('key', 'operator', 'value', 'prev');
    }

    public function having($column, $operator, $value, $prev)
    {
        $this->buildQuery['having'][] = [];
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

    #endregion

    #region CRUD Proccesses

    /**
     * get rows with query string
     * @return array
     */
    public function get(): array
    {
        $rows = $this->run()->fetchAll($this->buildQuery['fetchType']);
        if ($this->setClosures) $rows = $this->setClosures($rows);
        return $rows;
    }

    /**
     * Row count
     * @return int
     */
    public function count(): int
    {
        return $this->run()->rowCount();
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
     * Find or fail row by primary key
     * @param string $value
     * @return array 
     */
    public function findOrFail(string $value): array
    {
        return $this->where($this->getPrimary(), $value)->firstOrFail();
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
        if (!$cache_id) {
            Session::callback(function () {
                unset($_SESSION[$this->db][$this->dbname]['paginate']['cache']);
            });

            $cache = Session::callback(fn() => $_SESSION[$this->db][$this->dbname]['paginate']['cache'][$cache_id] ?? false);
            if ($cache) $row_count = $cache;
        }

        if (!isset($row_count)) {
            $snapshot = $this->buildQuery;

            # get row count
            $this->buildQuery['orderBy'] = [];
            $this->buildQuery['groupBy'] = [];
            $row_count = $this->select("COUNT(" . (!empty($this->buildQuery['join']) ? 'DISTINCT ' : NULL) . "{$this->table}.{$this->getPrimary()}) as count")->first()['count'];
            #

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
        $data      = count($data) ? $data : $this->buildQuery['data'] ?? [];
        $debug_sql = $sql;
        foreach ($this->buildQuery['data'] ?? [] as $key => $value) $debug_sql = str_replace(":$key", !$value ? 'null' : $this->connection()->quote($value), $debug_sql);
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
        $sql = $this->builder->build($type);

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
