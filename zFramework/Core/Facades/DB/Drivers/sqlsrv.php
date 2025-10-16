<?php

namespace zFramework\Core\Facades\DB\Drivers;

class sqlsrv extends mysql
{
    protected $parent;
    public function __construct($parent)
    {
        $this->parent = $parent;
        $GLOBALS['databases']['connected'][$this->parent->db]['name'] = $GLOBALS['databases']['connections'][$this->parent->db]->query('SELECT DB_NAME()')->fetchColumn();
    }

    /**
     * Table scheme blueprint
     * @return array
     */
    public function tables(): array
    {
        $engines = [];
        $tables  = $this->parent->prepare("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE = 'BASE TABLE' AND TABLE_CATALOG = :db", ['db' => $this->parent->dbname])->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($tables as $key => $table) {
            $tables[$key] = $table['TABLE_NAME'];
            $engines[$table['TABLE_NAME']] = null;
            $columns = $this->parent->prepare("SELECT c.COLUMN_NAME AS COLUMN_NAME, c.CHARACTER_MAXIMUM_LENGTH AS CHARACTER_MAXIMUM_LENGTH, c.DATA_TYPE AS COLUMN_TYPE, CASE WHEN tc.CONSTRAINT_TYPE='PRIMARY KEY' THEN 'PRI' ELSE '' END AS COLUMN_KEY FROM INFORMATION_SCHEMA.COLUMNS c LEFT JOIN INFORMATION_SCHEMA.CONSTRAINT_COLUMN_USAGE ccu ON c.TABLE_NAME=ccu.TABLE_NAME AND c.COLUMN_NAME=ccu.COLUMN_NAME LEFT JOIN INFORMATION_SCHEMA.TABLE_CONSTRAINTS tc ON ccu.CONSTRAINT_NAME=tc.CONSTRAINT_NAME AND tc.CONSTRAINT_TYPE='PRIMARY KEY' WHERE c.TABLE_CATALOG=:db AND c.TABLE_NAME=:table ORDER BY c.ORDINAL_POSITION", ['db' => $this->parent->dbname, 'table' => $table['TABLE_NAME']])->fetchAll(\PDO::FETCH_ASSOC);
            $primary = $this->parent->prepare("SELECT c.COLUMN_NAME AS COLUMN_NAME FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS tc JOIN INFORMATION_SCHEMA.CONSTRAINT_COLUMN_USAGE ccu ON tc.CONSTRAINT_NAME=ccu.CONSTRAINT_NAME JOIN INFORMATION_SCHEMA.COLUMNS c ON c.TABLE_NAME=ccu.TABLE_NAME AND c.COLUMN_NAME=ccu.COLUMN_NAME WHERE tc.CONSTRAINT_TYPE='PRIMARY KEY' AND c.TABLE_NAME = :table AND c.TABLE_CATALOG = :db", ['table' => $table['TABLE_NAME'], 'db' => $this->parent->dbname])->fetch(\PDO::FETCH_ASSOC);
            $data["TABLE_COLUMNS"][$table['TABLE_NAME']] = [
                'primary' => $primary['COLUMN_NAME'] ?? null,
                'columns' => $columns
            ];
        }

        $data["TABLES"]        = $tables;
        $data["TABLE_ENGINES"] = $engines;

        return $data;
    }

    /**
     * Get limits
     * @return null|string
     */
    private function getLimit(): null|string
    {
        $limit = @$this->parent->buildQuery['limit'];
        return $limit ? " OFFSET " . (!$limit[1] ? $limit[0] : 0) . " ROWS FETCH NEXT " . ($limit[1] ? $limit[1] : $limit[0]) . " ROWS ONLY" : null;
    }
}
