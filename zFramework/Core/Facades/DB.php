<?php

namespace zFramework\Core\Facades;

use ReflectionClass;
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
    public $cache_dir  = FRAMEWORK_PATH . "/Caches/DB";

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
     * @return mixed
     */
    public function __construct(?string $db = null)
    {
        if ($db && isset($GLOBALS['databases']['connections'][$db])) $this->db = $db;
        else $this->db = array_keys($GLOBALS['databases']['connections'])[0];

        $this->db();
        $this->reset();
    }

    /**
     * Create database connection or return already current connection.
     * @return object
     */
    public function db()
    {
        if ($this->connection !== null) return $this->connection;
        if (!isset($GLOBALS['databases']['connections'][$this->db])) die('Böyle bir veritabanı yok!');
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
    public function prepare(string $sql, array $data = [])
    {
        $e = $this->db()->prepare($sql);
        $e->execute(count($data) ? $data : $this->buildQuery['data'] ?? []);
        $this->reset();
        return $e;
    }

    /**
     * Select table.
     * @param string $table
     * @return self
     */
    public function table(string $table)
    {
        $this->table         = $table;
        $this->originalTable = $table;
        return $this;
    }

    /**
     * Set all tables informations in database.
     * @return void
     */
    private function tables(): void
    {
        $data = json_decode(@file_get_contents($this->cache_dir . "/" . $this->dbname . "/scheme.json"), true) ?? false;
        if (!$data) {
            $data = $this->builder->tables();
            file_put_contents2($this->cache_dir . "/" . $this->dbname . "/scheme.json", json_encode($data, JSON_UNESCAPED_UNICODE));
        }
        $GLOBALS['DB'][$this->dbname] = $data;
    }

    /**
     * Get primary key.
     * @return string
     */
    private function getPrimary()
    {
        if (!$this->table) throw new \Exception('firstly you must select a table for get primary key.');
        return $this->primary ?? @$GLOBALS["DB"][$this->dbname]["TABLE_COLUMNS"][$this->table]['primary'] ?? null;
    }

    #region Columns Controls

    /**
     * Get table columns
     * @return array
     */
    public function columns()
    {
        $columns = array_column($GLOBALS["DB"][$this->dbname]["TABLE_COLUMNS"][$this->table]['columns'], 'COLUMN_NAME');
        if (count($this->guard ?? [])) $columns = array_diff($columns, $this->guard);
        return $columns;
    }

    /**
     * Get table column's lengths 
     * @return array
     */
    public function columnsLength()
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
    public function compareColumnsLength(array $data)
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
    private function trigger(string $name, array $args = [])
    {
        if (!isset($this->observe)) return false;
        return call_user_func_array([new ($this->observe), 'router'], [$name, $args]);
    }

    /**
     * Reset build.
     * @return self
     */
    private function resetBuild()
    {
        $this->cache['buildQuery'] = $this->buildQuery;
        $this->buildQuery = [
            'select'    => [],
            'join'      => [],
            'where'     => [],
            'orderBy'   => [],
            'groupBy'   => [],
            'limit'     => [],
            'having'    => [],
            'sets'      => "",
            'fetchType' => \PDO::FETCH_ASSOC
        ];
        return $this;
    }

    /**
     * Model's relatives.
     * @return self
     */
    private function closures()
    {
        if (isset($GLOBALS['model-closures'][$this->db][$this->table])) return $this;

        $closures = [];
        foreach ((new ReflectionClass($this))->getMethods() as $closure) if (strstr($closure->class, 'Models') && !in_array($closure->name, $this->not_closures)) $closures[] = $closure->name;
        $GLOBALS['model-closures'][$this->db][$this->table] = $closures;
        return $this;
    }

    /**
     * Add Closure on/off.
     * @return self
     */
    public function closureMode(bool $mode = true)
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
    public function fetchType(null|string $type = null)
    {
        $this->buildQuery['fetchType'] = ['unique' => \PDO::FETCH_UNIQUE, 'lazy' => \PDO::FETCH_LAZY, 'keypair' => \PDO::FETCH_KEY_PAIR][$type] ?? \PDO::FETCH_ASSOC;
        return $this;
    }

    /**
     * Begin query for models.
     * this is empty
     * @return $this
     */
    public function beginQuery()
    {
        return $this;
    }

    /**
     * Reset all data.
     * @return self
     */
    public function reset()
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
     * @return string
     */
    public function hashedKey(string $key): string
    {
        return uniqid(str_replace(".", "_", $key) . "_");
    }
    #endregion

    #region BUILD QUERIES
    /**
     * Set Select
     * @param array|string $select
     * @return self
     */
    public function select($select)
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
    public function join(string $type, string $model, string $on = "")
    {
        $this->buildQuery['join'][] = [$type, $model, $on];
        return $this;
    }

    /**
     * add a "AND" where
     * @return self
     */
    public function where()
    {
        $this->wherePrev = 'AND';
        return self::addWhere(func_get_args());
    }

    /**
     * add a "OR" where
     * @return self
     */
    public function whereOr()
    {
        $this->wherePrev = 'OR';
        return self::addWhere(func_get_args());
    }

    /**
     * Add where item.
     * @param array $parameters
     * @return self
     */
    private function addWhere(array $parameters)
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
     * Where In sql build.
     * @param string $column
     * @param array $in
     * @param string $prev
     * @return self
     */
    public function whereIn(string $column, array $in = [], string $prev = "AND")
    {
        $hashed_keys = [];
        foreach ($in as $val) {
            $hashed_key    = $this->hashedKey($column);
            $hashed_keys[] = $hashed_key;
            $this->buildQuery['data'][$hashed_key] = $val;
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
    public function whereNotIn(string $column, array $in = [], string $prev = "AND")
    {
        $hashed_keys = [];
        foreach ($in as $val) {
            $hashed_key    = $this->hashedKey($column);
            $hashed_keys[] = $hashed_key;
            $this->buildQuery['data'][$hashed_key] = $val;
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
    public function whereBetween(string $column, $start, $stop, string $prev = 'AND')
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
    public function whereNotBetween(string $column, $start, $stop, string $prev = 'AND')
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
    public function whereRaw(string $sql, array $data = [], string $prev = "AND")
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
        foreach ($data as $key => $val) $this->buildQuery['data'][$key] = $val;

        return $this;
    }

    /**
     * Prepare where
     * @param array $data
     */
    private function prepareWhere(array $data)
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

    /**
     * Set Order By
     * @param array $data
     * @return self
     */
    public function orderBy(array $data = [])
    {
        $this->buildQuery['orderBy'] = $data;
        return $this;
    }

    /**
     * Set Group By
     * @param array $data
     * @return self
     */
    public function groupBy(array $data = [])
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
    public function limit(int $startPoint = 0, $getCount = null)
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
    public function get()
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
    public function first()
    {
        return $this->limit(1)->get()[0] ?? [];
    }

    /**
     * Find row by primary key
     * @param string $value
     * @return array 
     */
    public function find(string $value)
    {
        return $this->where($this->getPrimary(), $value)->first();
    }

    /**
     * paginate
     * @param int $per_count
     * @param string $page_name
     * @return array
     */
    public function paginate(int $per_page = 20, string $page_id = 'page')
    {
        $last_query       = $this->buildQuery;
        $row_count        = $this->select("COUNT($this->table." . $this->getPrimary() . ") count")->first()['count'];
        $this->buildQuery = $last_query;

        $uniqueID         = uniqid();
        $current_page     = (request($page_id) ?? 1);
        $page_count       = ceil($row_count / $per_page);

        if ($current_page > $page_count) $current_page = $page_count;
        elseif ($current_page <= 0) $current_page = 1;

        $start_count = ($per_page * ($current_page - 1));
        if (!$row_count) $start_count = -1;

        parse_str(@$_SERVER['QUERY_STRING'], $queryString);
        $queryString[$page_id] = "change_page_$uniqueID";
        $url = "?" . http_build_query($queryString);

        return [
            'items'          => $row_count ? self::limit($start_count, $per_page)->get() : [],
            'item_count'     => $row_count,
            'shown'          => ($start_count + 1) . " / " . (($per_page * $current_page) >= $row_count ? $row_count : ($per_page * $current_page)),
            'start'          => ($start_count + 1),

            'per_page'       => $per_page,
            'page_count'     => $page_count,
            'current_page'   => $current_page,

            'links'          => function ($view = null) use ($page_count, $current_page, $url, $uniqueID) {
                if (!$view) $view = config('app.pagination.default-view');

                $pages = [];
                for ($x = 1; $x <= $page_count; $x++) {
                    $pages[$x] = [
                        'type'    => 'page',
                        'page'    => $x,
                        'current' => $x == $current_page,
                        'url'     => str_replace("change_page_$uniqueID", $x, $url)
                    ];
                }

                return view($view, compact('pages', 'page_count', 'current_page', 'url', 'uniqueID'));
            }
        ];
    }

    /**
     * Insert a row to database
     * @param array $sets
     * @return self
     */
    public function insert(array $sets = [])
    {
        $this->resetBuild();

        if ($new_sets = $this->trigger('insert', $sets)) $sets = $new_sets;

        $hashed_keys = [];
        foreach ($sets as $key => $value) {
            $hashed_key    = $this->hashedKey($key);
            $hashed_keys[] = $hashed_key;
            $this->buildQuery['data'][$hashed_key] = $value;
        }

        $this->buildQuery['sets'] = " (" . implode(', ', array_keys($sets)) . ") VALUES (:" . implode(', :', $hashed_keys) . ") ";
        $insert = $this->run(__FUNCTION__)->rowCount();
        if ($insert && $primary = $this->getPrimary()) {
            $inserted_row = $this->resetBuild()->where($primary, $this->db()->lastInsertId())->first() ?? [];
            $this->trigger('inserted', $inserted_row);
        }

        return isset($inserted_row) ? $inserted_row : $insert;
    }

    /**
     * Update row(s) in database
     * @param array $sets
     * @return self
     */
    public function update(array $sets = [])
    {
        $this->buildQuery['sets'] = " SET ";

        if ($new_sets = $this->trigger('update', $sets)) $sets = $new_sets;

        foreach ($sets as $key => $value) {
            $hashed_key = $this->hashedKey($key);
            $this->buildQuery['data'][$hashed_key] = $value;
            $this->buildQuery['sets'] .= "$key = :$hashed_key, ";
        }

        $this->buildQuery['sets'] = rtrim($this->buildQuery['sets'], ', ');
        $update = $this->run(__FUNCTION__)->rowCount();
        if ($update) $this->trigger('updated');

        return $update;
    }

    /**
     * Delete row(s) in database
     * @return self
     */
    public function delete()
    {
        $this->trigger('delete');
        if (!isset($this->softDelete)) $delete = $this->run(__FUNCTION__)->rowCount();
        else $delete = $this->update([$this->deleted_at => date('Y-m-d H:i:s')]);
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
    public function sqlDebug(bool $mode)
    {
        $this->sqlDebug = $mode;
        return $this;
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

        // if ($this->sqlDebug) {
        //     $debug_sql = $sql;
        //     foreach ($this->buildQuery['data'] ?? [] as $key => $value) $debug_sql = str_replace(":$key", $this->db()->quote($value), $debug_sql);
        //     echo "#Begin SQL Query:\n";
        //     var_dump($debug_sql);
        //     echo "#End of SQL Query\n";
        // }

        if ($this->sqlDebug) {
            $debug_sql = $sql;
            foreach ($this->buildQuery['data'] ?? [] as $key => $value) $debug_sql = str_replace(":$key", $this->db()->quote($value), $debug_sql);
            ob_start();
            echo "#" . $this->dbname . " Begin SQL Query:\n";
            var_dump($debug_sql);
            echo "#End of SQL Query\n";
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
    public function run(string $type = 'select')
    {
        return $this->prepare($this->buildSQL($type));
    }
    #endregion

    #region Transaction

    /**
     * Check table is using InnoDB engine.
     * @return bool
     */
    private function checkisInnoDB()
    {
        if (empty($this->table)) throw new \Exception('This table is not defined.');
        if ($GLOBALS["DB"][$this->dbname]["TABLE_ENGINES"][$this->table] == 'InnoDB') return true;
        throw new \Exception('This table is not InnoDB. If you want to use transaction system change store engine to InnoDB.');
    }

    /**
     * Begin transaction.
     */
    public function beginTransaction()
    {
        $this->checkisInnoDB();
        $this->db()->beginTransaction();
        return $this;
    }

    /**
     * Rollback changes.
     */
    public function rollback()
    {
        $this->db()->rollBack();
        return $this;
    }

    /**
     * Save all changes.
     */
    public function commit()
    {
        $this->db()->commit();
        return $this;
    }
    #endregion
}
