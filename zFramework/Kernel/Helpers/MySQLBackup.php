<?php

namespace zFramework\Kernel\Helpers;

use PDO;

class MySQLBackup
{

    private $config = [];
    private $db;
    private $dbname;
    private $sql;

    /**
     * Backup constructor.
     * @param $config
     */
    public function __construct($db, $config = [])
    {
        $this->db      = $db;
        $this->dbname  = $this->db->prepare('select database()')->fetchColumn();
        $this->config  = $config;
    }

    /**
     * @return bool|int
     */
    public function backup()
    {
        @mkdir($this->config['dir'], 0777, true);

        $tables = $this->getAll('SHOW TABLES');

        foreach ($tables as $table) {

            $tableName = current($table);

            /**
             * Tablo satırları
             */
            $rows = $this->getAll('SELECT * FROM %s', [$tableName]);

            $this->sql .= '-- Tablo Adı: ' . $tableName . "\n-- Satır Sayısı: " . count($rows) . str_repeat(PHP_EOL, 2);

            /**
             * Tablo detayları
             */
            $tableDetail = $this->getFirst('SHOW CREATE TABLE %s', [$tableName]);
            $this->sql .= $tableDetail['Create Table'] . ';' . str_repeat(PHP_EOL, 3);

            /**
             * Satır sayısı 0dan büyükse
             */
            if (count($rows) > 0) {

                $columns = $this->getAll('SHOW COLUMNS FROM %s', [$tableName]);
                $columns = array_map(fn($column) => $column['Field'], $columns);

                // INSERT INTO kategoriler (kategori_id, kategori_adi) VALUES (1,'test'), (2, 'test2')

                // $this->sql .= 'INSERT INTO `' . $tableName . '` (`' . implode('`,`', $columns) . '`) VALUES ' . PHP_EOL;

                // $columnsData = [];
                // foreach ($rows as $row) {
                //     $row = array_map(function ($item) {
                //         return $this->db->quote($item);
                //     }, $row);
                //     $columnsData[] = '(' . implode(',', $row) . ')';
                // }
                // $this->sql .= implode(',' . PHP_EOL, $columnsData) . ';' . str_repeat(PHP_EOL, 5);

                foreach ($rows as $row) $this->sql .= 'INSERT INTO `' . $tableName . '` (`' . implode('`,`', $columns) . '`) VALUES  (' . implode(',', array_map(fn($item) => $this->db->quote($item), $row)) . ');' . PHP_EOL;
            }
        }

        // Triggerlar için metod
        $this->dumpTriggers();

        // Fonksiyonlar için metod
        $this->dumpFunctions();

        // Procedure için metod
        $this->dumpProcedures();

        if (strlen($this->sql) == 0) return false;

        $save_path = $this->config['dir'] . "/" . str_replace('{dbname}', $this->dbname, $this->config['save_as']);
        $ext       = ($this->config['compress'] ? '.sql.gz' : '.sql');
        if (file_exists($save_path . $ext)) $save_path = "$save_path (" . count(glob($this->config['dir'] . "/*" . $ext)) . ")";
        $save_path .= $ext;

        if (!$this->config['compress']) {
            $write = file_put_contents($save_path, $this->sql);
        } else {
            $write = gzopen($save_path, "a9");
            gzwrite($write, $this->sql);
            gzclose($write);
        }

        return $write;
    }

    /**
     * @param $query
     * @param array $params
     * @return mixed
     */
    private function getFirst($query, $params = [])
    {
        return $this->db->query(vsprintf($query, $params))->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * @param $query
     * @param array $params
     * @return mixed
     */
    private function getAll($query, $params = [])
    {
        return $this->db->query(vsprintf($query, $params))->fetchAll(PDO::FETCH_ASSOC);
    }

    private function dumpTriggers()
    {

        $triggers = $this->getAll('SHOW TRIGGERS');
        if (count($triggers) > 0) {
            $this->sql .= '-- TRIGGERS (' . count($triggers) . ')' . str_repeat(PHP_EOL, 2);
            $this->sql .= 'DELIMITER //' . PHP_EOL;
            foreach ($triggers as $trigger) {
                $query = $this->getFirst('SHOW CREATE TRIGGER %s', [$trigger['Trigger']]);
                $this->sql .= $query['SQL Original Statement'] . '//' . PHP_EOL;
            }
            $this->sql .= 'DELIMITER ;' . str_repeat(PHP_EOL, 5);
        }
    }

    private function dumpFunctions()
    {

        $functions = $this->getAll('SHOW FUNCTION STATUS WHERE Db = "%s"', [$this->dbname]);
        if (count($functions) > 0) {
            $this->sql .= '-- FUNCTIONS (' . count($functions) . ')' . str_repeat(PHP_EOL, 2);
            $this->sql .= 'DELIMITER //' . PHP_EOL;
            foreach ($functions as $function) {
                $query = $this->getFirst('SHOW CREATE FUNCTION %s', [$function['Name']]);
                $this->sql .= $query['Create Function'] . '//' . PHP_EOL;
            }
            $this->sql .= 'DELIMITER ;' . str_repeat(PHP_EOL, 5);
        }
    }

    private function dumpProcedures()
    {
        $procedures = $this->getAll('SHOW PROCEDURE STATUS WHERE Db = "%s"', [$this->dbname]);
        if (count($procedures) > 0) {
            $this->sql .= '-- PROCEDURES (' . count($procedures) . ')' . str_repeat(PHP_EOL, 2);
            $this->sql .= 'DELIMITER //' . PHP_EOL;
            foreach ($procedures as $procedure) {
                $query = $this->getFirst('SHOW CREATE PROCEDURE %s', [$procedure['Name']]);
                $this->sql .= $query['Create Procedure'] . '//' . PHP_EOL;
            }
            $this->sql .= 'DELIMITER ;' . str_repeat(PHP_EOL, 5);
        }
    }
}
