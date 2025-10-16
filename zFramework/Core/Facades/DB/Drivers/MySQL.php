<?php

namespace zFramework\Core\Facades\DB\Drivers;

class MySQL
{
    protected $parent;
    public function __construct($parent)
    {
        $this->parent = $parent;
    }

    /**
     * Get Select
     * @return null|string
     */
    private function getSelect(): null|string
    {
        switch (gettype($this->parent->buildQuery['select'])) {
            case 'array':
                if (!count($this->parent->buildQuery['select'])) return null;
                return is_array(($select = $this->parent->buildQuery['select'])) ? implode(', ', $select) : $select;
                break;

            case 'string':
                return $this->parent->buildQuery['select'];
                break;

            default:
                return null;
        }
    }

    /**
     * get joins output
     * @return string
     */
    private function getJoin(): string
    {
        $output = "";
        foreach ($this->parent->buildQuery['join'] as $join) {
            $model = new $join[1]();
            $output .= " " . $join[0] . " JOIN $model->table ON " . $join[2] . " ";
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
     * Parse and get where.
     * @return null|string
     */
    private function getWhere($checkSoftDelete = true): null|string
    {
        if ($checkSoftDelete && isset($this->parent->softDelete)) $this->parent->buildQuery['where'][] = [
            'type'     => 'row',
            'queries'  => [
                [
                    'key'      => $this->parent->deleted_at,
                    'operator' => 'IS NULL',
                    'value'    => null,
                    'prev'     => "AND"
                ]
            ]
        ];

        if (!count($this->parent->buildQuery['where'])) return null;

        $output = "";
        foreach ($this->parent->buildQuery['where'] as $where_key => $where) {
            $response = "";
            foreach ($where['queries'] as $query_key => $query) {
                $query['prev'] = strtoupper($query['prev']);

                if (!isset($query['raw'])) if (strlen($query['value'] ?? '') > 0) {
                    $hashed_key = $this->parent->hashedKey($query['key']);
                    $this->parent->buildQuery['data'][$hashed_key] = $query['value'];
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
     * Get order by list
     * @return string
     */
    private function getOrderBy(): string
    {
        $orderBy = $this->parent->buildQuery['orderBy'] ?? [];
        if (!count($orderBy)) return "";

        $orderByStr = '';
        foreach ($orderBy as $column => $order) $orderByStr .= "$column $order, ";
        $orderByStr = rtrim($orderByStr, ', ');
        return " ORDER BY $orderByStr ";
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

        switch ($type) {
            case 'select':
                $select = $this->getSelect();
                $select = strlen($select ?? '') ? $select : (count($this->parent->guard ?? []) ? "$table." . implode(", $table.", $this->parent->columns()) : "$table.*");
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

        return "$type " . $this->parent->table . " " . @$sets . $this->getJoin() . $this->getWhere($checkSoftDelete) . $this->getGroupBy() . $this->getOrderBy() . $this->getLimit();
    }
}
