<?php

namespace zFramework\Core\Facades\DB\Drivers;

class mysql
{
    protected $parent;
    public function __construct($parent)
    {
        $this->parent = $parent;

        # Ask the server for its database name once per connection, not once per builder.
        if (!isset($GLOBALS['databases']['connected'][$this->parent->db]['name']))
            $GLOBALS['databases']['connected'][$this->parent->db]['name'] = $GLOBALS['databases']['connections'][$this->parent->db]->query('SELECT DATABASE()')->fetchColumn();
    }

    /**
     * Point the shared builder at the DB instance that is about to use it.
     *
     * One builder is reused per connection, so its owner has to be refreshed
     * right before every build() / tables() call.
     *
     * @param object $parent
     * @return self
     */
    public function setParent($parent): self
    {
        $this->parent = $parent;
        return $this;
    }

    /**
     * Table scheme blueprint
     * @return array
     */
    public function tables(): array
    {
        $engines = [];
        $tables  = $this->parent->prepare("SELECT TABLE_NAME, ENGINE FROM information_schema.tables WHERE table_schema = :table_scheme", ['table_scheme' => $this->parent->dbname])->fetchAll(\PDO::FETCH_ASSOC);

        # Every column of every table in one round-trip rather than a query per
        # table. information_schema is slow enough that the number of queries is
        # what costs, not the size of the answer - and this is paid in full
        # whenever the scheme cache is cold: after a deploy, after a migration
        # drops scheme.json, or when a new tenant connects.
        $rows = $this->parent->prepare("SELECT TABLE_NAME, COLUMN_NAME, CHARACTER_MAXIMUM_LENGTH, COLUMN_TYPE, COLUMN_KEY FROM information_schema.columns WHERE table_schema = :table_scheme ORDER BY TABLE_NAME, ORDINAL_POSITION", ['table_scheme' => $this->parent->dbname])->fetchAll(\PDO::FETCH_ASSOC);

        $grouped = [];
        foreach ($rows as $row) {
            $table = $row['TABLE_NAME'];
            unset($row['TABLE_NAME']); # the per-table shape never carried it
            $grouped[$table][] = $row;
        }

        # Initialised, so a schema with no tables still returns the same shape.
        $data = ["TABLE_COLUMNS" => []];

        foreach ($tables as $key => $table) {
            $name          = $table['TABLE_NAME'];
            $tables[$key]  = $name;
            $engines[$name] = $table['ENGINE'];

            $columns = $grouped[$name] ?? [];
            $data["TABLE_COLUMNS"][$name] = [
                'primary' => (($idx = array_search("PRI", array_column($columns, 'COLUMN_KEY'))) !== false) ? $columns[$idx]['COLUMN_NAME'] : null,
                'columns' => $columns
            ];
        }

        $data["TABLES"]         = $tables;
        $data["TABLE_ENGINES"]  = $engines;

        return $data;
    }

    /**
     * Get Select
     * @return null|string
     */
    private function getSelect(): null|string
    {
        return [
            'array'  => fn() => count($this->parent->buildQuery['select']) ? (is_array(($select = $this->parent->buildQuery['select'])) ? implode(', ', $select) : $select) : null,
            'string' => fn() => $this->parent->buildQuery['select']
        ][gettype($this->parent->buildQuery['select'])]() ?? null;
    }

    /**
     * get joins output
     * @return string
     */
    private function getJoin(): string
    {
        $output = "";
        foreach ($this->parent->buildQuery['join'] as $join) {
            $table = class_exists($join[1]) ? (new $join[1])->table : $join[1];
            $output .= " " . $join[0] . " JOIN $table ON " . $join[2] . " ";
        }
        return $output;
    }

    /**
     * Get limits
     * @return null|string
     */
    private function getLimit(): null|string
    {
        $limit = @$this->parent->buildQuery['limit'];
        return $limit ? " LIMIT " . ($limit[0] . ($limit[1] ? ", " . $limit[1] : null)) : null;
    }

    /**
     * Get group by list
     * @return null|string
     */
    private function getGroupBy(): null|string
    {
        return @$this->parent->buildQuery['groupBy'] ? " GROUP BY " . implode(", ", $this->parent->buildQuery['groupBy']) : null;
    }

    /**
     * Parse and get where or having.
     * @param bool $checkSoftDelete
     * @param string $gettype
     * @return null|string
     */
    private function getWhereOrHaving($checkSoftDelete = true, string $gettype = 'where'): null|string
    {
        if ($checkSoftDelete && isset($this->parent->softDelete)) $this->parent->buildQuery[$gettype][] = [
            'type'     => 'row',
            'queries'  => [
                [
                    'key'      => $this->parent->table . '.' . $this->parent->deleted_at,
                    'prev'     => "AND"
                ] + [
                    'date' => ['operator' => 'IS NULL', 'value' => null],
                    'bool' => ['operator' => '=', 'value' => 1]
                ][$this->parent->deleted_at_type]
            ]
        ];

        if (!count($this->parent->buildQuery[$gettype])) return null;

        $output = "";
        foreach ($this->parent->buildQuery[$gettype] as $where_key => $where) {
            $response = "";
            foreach ($where['queries'] as $query_key => $query) {
                $query['prev'] = strtoupper($query['prev']);

                if (!isset($query['raw'])) if ($query['value'] !== null) {
                    $hashed_key = $this->parent->hashedKey($query['key']);
                    $this->parent->buildQuery['data'][$hashed_key] = $query['value'];
                }

                if (count($where['queries']) == 1) $prev = ($where_key + $query_key > 0) ? $query['prev'] : null;
                else $prev = ($query_key > 0) ? $query['prev'] : null;

                $response .= implode(" ", [
                    $prev,
                    $query['key'],
                    $query['operator'],
                    (isset($query['raw']) ? $query['value'] . " " : ($query['value'] !== null ? ":$hashed_key " : null))
                ]);
            }

            if ($where['type'] == 'group') $response = (!empty($output) ? $where['queries'][0]['prev'] . " " : null) . "(" . rtrim($response) . ") ";
            $output .= $response;
        }

        return " " . strtoupper($gettype) . " $output ";
    }

    /**
     * Get order by list
     * @return string|null
     */
    private function getOrderBy(): string|null
    {
        $orderBy = $this->parent->buildQuery['orderBy'] ?? [];
        if (!count($orderBy)) return null;

        $output = '';
        foreach ($orderBy as $column => $order) $output .= "$column $order, ";
        $output = rtrim($output, ', ');
        return " ORDER BY $output ";
    }


    /**
     * Build SQL
     * @param string $type
     * @return string
     */
    public function build(string $type): string
    {
        $table           = $this->parent->table;
        $checkSoftDelete = true;
        $limit           = $this->getLimit();

        switch ($type) {
            case 'select':
                $select = $this->getSelect();
                $select = strlen($select ?? '') ? $select : (count($this->parent->guard ?? []) ? "$table." . implode(", $table.", $this->parent->columns()) : "$table.*");
                # Appended rather than merged into select() so an explicit select() call
                # cannot drop the ranking column, and vice versa. See DB::withRealOrder().
                if ($realOrder = $this->parent->buildQuery['realOrder'] ?? null) $select .= ", $realOrder";
                $type   = "SELECT $select FROM";
                break;

            case 'delete':
                $type = "DELETE FROM";
                break;

            case 'insert':
                $type = "INSERT INTO";
                $sets = $this->parent->buildQuery['sets'];
                $checkSoftDelete = false;
                break;

            case 'update':
                $type = "UPDATE";
                $sets = $this->parent->buildQuery['sets'];
                break;

            default:
                throw new \Exception('something wrong, build invalid type.');
        }

        return "$type " . $this->parent->table . " " . @$sets . $this->getJoin() . $this->getWhereOrHaving($checkSoftDelete) . $this->getGroupBy() . $this->getWhereOrHaving(false, 'having') . $this->getOrderBy() . $limit;
    }
}
