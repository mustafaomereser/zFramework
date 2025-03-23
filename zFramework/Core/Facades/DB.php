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
    private $driver;
    private $dbname;
    private $sql_debug = false;
    private $wherePrev = 'AND';
    /**
     * Options parameters
     */
    public $table;
    public $buildQuery   = [];
    public $cache        = [];
    public $specialChars = false;
    public $setClosures  = true;

    /**
     * Initial, Select Database.
     * @param string @db
     * @return mixed
     */
    public function __construct(string $db = null)
    {
        if ($db && isset($GLOBALS['databases']['connections'][$db])) $this->db = $db;
        else $this->db = array_keys($GLOBALS['databases']['connections'])[0];

        $this->db();
        $this->reset();
    }

    /**
     * Create database connection or return already current connection.
     */
    public function db()
    {
        if (!isset($GLOBALS['databases']['connections'][$this->db])) die('Böyle bir veritabanı yok!');
        if (!isset($GLOBALS['databases']['connected'][$this->db])) {
            try {
                $parameters = $GLOBALS['databases']['connections'][$this->db];
                $conenction = new \PDO($parameters[0], $parameters[1], ($parameters[2] ?? null));
                foreach ($parameters['options'] ?? [] as $option) $conenction->setAttribute($option[0], $option[1]);
            } catch (\Throwable $err) {
                die(errorHandler($err));
            }

            $GLOBALS['databases']['connected'][$this->db]   = ['name' => $conenction->query('SELECT DATABASE()')->fetchColumn(), 'driver' => $conenction->getAttribute(\PDO::ATTR_DRIVER_NAME)];
            $GLOBALS['databases']['connections'][$this->db] = $conenction;

            $new_connection = true;
        }

        $this->dbname = $GLOBALS['databases']['connected'][$this->db]['name'];
        $this->driver = $GLOBALS['databases']['connected'][$this->db]['driver'];

        if (isset($new_connection)) $this->tables();

        return $GLOBALS['databases']['connections'][$this->db];
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
        $this->table = $table;
        return $this;
    }

    /**
     * Fetch all tables in database.
     * @return array
     */
    private function tables()
    {
        $engines = [];
        $tables  = $this->prepare("SELECT TABLE_NAME, ENGINE FROM information_schema.tables WHERE table_schema = :table_scheme", ['table_scheme' => $this->dbname])->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($tables as $key => $table) {
            $tables[$key] = $table['TABLE_NAME'];
            $engines[$table['TABLE_NAME']] = $table['ENGINE'];

            $columns = $this->prepare("SELECT COLUMN_NAME, CHARACTER_MAXIMUM_LENGTH, COLUMN_TYPE, COLUMN_KEY FROM information_schema.columns where table_schema = DATABASE() AND table_name = :table", ['table' => $table['TABLE_NAME']])->fetchAll(\PDO::FETCH_ASSOC);
            $GLOBALS["DB"][$this->dbname]["TABLE_COLUMNS"][$table['TABLE_NAME']] = [
                'primary' => $columns[array_search("PRI", array_column($columns, 'COLUMN_KEY'))]['COLUMN_NAME'],
                'columns' => $columns
            ];
        }

        $GLOBALS["DB"][$this->dbname]["TABLES"]         = $tables;
        $GLOBALS["DB"][$this->dbname]["TABLE_ENGINES"]  = $engines;
    }

    /**
     * Get primary key.
     */
    private function getPrimary()
    {
        if (!$this->table) throw new \Exception('firstly you must select a table for get primary key.');
        return $this->primary ?? $GLOBALS["DB"][$this->dbname]["TABLE_COLUMNS"][$this->table]['primary'];
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
        if (!isset($this->observe) || !count($this->observe)) return false;
        $output = [];
        foreach ($this->observe as $observe) $output[] = call_user_func_array([new $observe, 'router'], [$name, $args]);
        return $output[$this->observe_key ?? 0];
    }

    /**
     * Reset build.
     * @return self
     */
    private function resetBuild()
    {
        $this->cache['buildQuery'] = $this->buildQuery;
        $this->buildQuery = [
            'select'  => [],
            'join'    => [],
            'where'   => [],
            'orderBy' => [],
            'groupBy' => [],
            'limit'   => [],
            'sets'    => ""
        ];
        return $this;
    }

    /**
     * Model's relatives.
     * @return self
     */
    private function closures()
    {
        if (isset($GLOBALS['model-closures'][$this->table])) return $this;

        $closures = [];
        foreach ((new ReflectionClass($this))->getMethods() as $closure) if (strstr($closure->class, 'Models') && !in_array($closure->name, $this->not_closures)) $closures[] = $closure->name;
        $GLOBALS['model-closures'][$this->table] = $closures;
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
     * Get Select
     * @return null|string
     */
    private function getSelect()
    {
        switch (gettype($this->buildQuery['select'])) {
            case 'array':
                if (!count($this->buildQuery['select'])) return null;
                return is_array(($select = $this->buildQuery['select'])) ? implode(', ', $select) : $select;
                break;

            case 'string':
                return $this->buildQuery['select'];
                break;

            default:
                return null;
        }
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
     * get joins output
     * @return string
     */
    private function getJoin(): string
    {
        $output = "";
        foreach ($this->buildQuery['join'] as $join) {
            $model = new $join[1]();
            $output .= " " . $join[0] . " JOIN $model->table ON " . $join[2] . " ";
        }
        return $output;
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
     * Parse and get where.
     * @return void|string
     */
    private function getWhere()
    {
        if (isset($this->softDelete)) $this->buildQuery['where'][] = [
            'type'     => 'row',
            'queries'  => [
                [
                    'key'      => $this->deleted_at,
                    'operator' => 'IS NULL',
                    'value'    => null,
                    'prev'     => "AND"
                ]
            ]
        ];

        if (!count($this->buildQuery['where'])) return;

        $output = "";
        foreach ($this->buildQuery['where'] as $where_key => $where) {
            $response = "";
            foreach ($where['queries'] as $query_key => $query) {
                $query['prev'] = strtoupper($query['prev']);

                if (!isset($query['raw'])) if (strlen($query['value'] ?? '') > 0) {
                    $hashed_key = $this->hashedKey($query['key']);
                    $this->buildQuery['data'][$hashed_key] = $query['value'];
                }

                if (count($where['queries']) == 1) $prev = ($where_key + $query_key > 0) ? $query['prev'] : null;
                else $prev = ($query_key > 0) ? $query['prev'] : null;

                $response .= implode(" ", [
                    $prev,
                    $query['key'],
                    $query['operator'],
                    (isset($query['raw']) ? $query['value'] . " " : (strlen($query['value'] ?? '') > 0 ? ":$hashed_key " : null))
                ]);
            }

            if ($where['type'] == 'group') $response = (!empty($output) ? $where['queries'][0]['prev'] . " " : null) . "(" . rtrim($response) . ") ";
            $output .= $response;
        }

        return " WHERE $output ";
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

    private function getOrderBy()
    {
        $orderBy = $this->buildQuery['orderBy'] ?? [];

        if (count($orderBy)) {
            $orderByStr = '';
            foreach ($orderBy as $column => $order) $orderByStr .= "$column $order, ";
            $orderByStr = rtrim($orderByStr, ', ');
            return " ORDER BY $orderByStr ";
        }

        return null;
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


    private function getGroupBy()
    {
        return @$this->buildQuery['groupBy'] ? " GROUP BY " . implode(", ", $this->buildQuery['groupBy']) : null;
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

    private function getLimit()
    {
        $limit = @$this->buildQuery['limit'];
        return $limit ? " LIMIT " . ($limit[0] . ($limit[1] ? ", " . $limit[1] : null)) : null;
    }
    #endregion

    #region CRUD Proccesses

    /**
     * get rows with query string
     * @return array
     */
    public function get()
    {
        $rows = $this->run()->fetchAll(\PDO::FETCH_ASSOC);
        if ($this->setClosures) {
            $primary_key = $this->getPrimary();
            foreach ($rows as $key => $row) {
                foreach ($GLOBALS['model-closures'][$this->table] as $closure) $rows[$key][$closure] = function () use ($row, $closure) {
                    return $this->{$closure}($row);
                };

                if (!isset($row[$primary_key])) continue;

                $rows[$key]['update'] = function ($sets) use ($row, $primary_key) {
                    return $this->where($primary_key, $row[$primary_key])->update($sets);
                };

                $rows[$key]['delete'] = function () use ($row, $primary_key) {
                    return $this->where($primary_key, $row[$primary_key])->delete();
                };

                // $rows[$key] = new \ArrayObject($rows[$key], \ArrayObject::ARRAY_AS_PROPS);
            }
        }

        // $rows = new \ArrayObject($rows, \ArrayObject::ARRAY_AS_PROPS);

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
            if ($this->specialChars) $value = htmlspecialchars($value);
            $hashed_key    = $this->hashedKey($key);
            $hashed_keys[] = $hashed_key;
            $this->buildQuery['data'][$hashed_key] = $value;
        }

        $this->buildQuery['sets'] = " (" . implode(', ', array_keys($sets)) . ") VALUES (:" . implode(', :', $hashed_keys) . ") ";

        $insert = $this->run(__FUNCTION__);
        if ($insert) {
            $inserted_row = $this->resetBuild()->where('id', $this->db()->lastInsertId())->first() ?? [];
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
            if ($this->specialChars) $value = htmlspecialchars($value);
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
        $this->sql_debug = $mode;
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
        $limit = $this->getLimit();
        switch ($type) {
            case 'select':
                $select = $this->getSelect();
                $select = strlen($select ?? '') ? $select : (count($this->guard ?? []) ? "$this->table." . implode(", $this->table.", $this->columns()) : "$this->table.*");
                $type   = "SELECT $select FROM";
                break;

            case 'delete':
                $type = "DELETE FROM";
                break;

            case 'insert':
                $type = "INSERT INTO";
                $sets = $this->buildQuery['sets'];
                break;

            case 'update':
                $type = "UPDATE";
                $sets = $this->buildQuery['sets'];
                break;

            default:
                throw new \Exception('something wrong, buildSQL invalid type.');
        }

        $sql = "$type $this->table" . @$sets . $this->getJoin() . $this->getWhere() . $this->getGroupBy() . $this->getOrderBy() . $limit;

        if ($this->sql_debug) {
            $debug_sql = $sql;
            foreach ($this->buildQuery['data'] ?? [] as $key => $value) $debug_sql = str_replace(":$key", $this->db()->quote($value), $debug_sql);

            echo "#Begin SQL Query:\n";
            var_dump($debug_sql);
            echo "#End of SQL Query\n";
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
