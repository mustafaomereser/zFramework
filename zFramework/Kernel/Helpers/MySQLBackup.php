<?php

namespace zFramework\Kernel\Helpers;

use PDO;
use zFramework\Kernel\Terminal;

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

        $this->sql = [];
        foreach ($tables as $table) {

            $tableName = current($table);

            /**
             * Tablo satırları
             */
            $rows = $this->getAll('SELECT * FROM %s', [$tableName]);

            @$this->sql[$tableName] .= '-- Tablo Adı: ' . $tableName . "\n-- Satır Sayısı: " . count($rows) . str_repeat(PHP_EOL, 2);

            /**
             * Tablo detayları
             */
            $tableDetail = $this->getFirst('SHOW CREATE TABLE %s', [$tableName]);
            $this->sql[$tableName] .= $tableDetail['Create Table'] . ';' . str_repeat(PHP_EOL, 3);

            /**
             * Satır sayısı 0dan büyükse
             */
            if (count($rows) > 0) {
                $columns = $this->getAll('SHOW COLUMNS FROM %s', [$tableName]);
                $columns = array_map(fn($column) => $column['Field'], $columns);
                foreach ($rows as $row) $this->sql[$tableName] .= 'INSERT INTO `' . $tableName . '` (`' . implode('`,`', $columns) . '`) VALUES (' . implode(',', array_map(fn($item) => !is_null($item) ? @$this->db->connection()->quote($item) : 'NULL', $row)) . ');' . PHP_EOL;
            }
        }

        return $this->save();
    }

    private function save()
    {
        $output = [];
        foreach ($this->sql as $table_name => $sql) @$output[$this->config['separate'] ? $this->dbname . "." . $table_name : $this->dbname] .= $sql;

        if (!count($output)) return Terminal::text("[color=dark-gray]-> $this->dbname is empty.[/color]");

        $this->dumpTriggers();
        $this->dumpFunctions();
        $this->dumpProcedures();

        $write = false;
        foreach ($output as $key => $sql) {
            $save_path = $this->config['dir'] . "/" . $this->dbname . "/" . $this->config['save_as'] . "/" . $key;
            $ext       = ($this->config['compress'] ? '.sql.gz' : '.sql');
            if (file_exists($save_path . $ext)) $save_path = "$save_path (" . count(glob($this->config['dir'] . "/*" . $ext)) . ")";
            $save_path .= $ext;

            if (!$this->config['compress']) {
                $write = file_put_contents2($save_path, $sql);
            } else {
                @mkdir(dirname($save_path), 0777, true);
                $write = gzopen($save_path, "a9");
                gzwrite($write, $sql);
                gzclose($write);
            }

            if ($write) Terminal::text("[color=green]-> `$key` backup `$save_path`[/color]");
            else Terminal::text("[color=red]-> $key Backup fail.[/color] [color=yellow]Check your database status.[/color]");
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
        return $this->db->prepare(vsprintf($query, $params))->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * @param $query
     * @param array $params
     * @return mixed
     */
    private function getAll($query, $params = [])
    {
        return $this->db->prepare(vsprintf($query, $params))->fetchAll(PDO::FETCH_ASSOC);
    }

    private function dumpTriggers()
    {
        try {
            $triggers = $this->getAll('SHOW TRIGGERS');
            if (count($triggers) > 0) {
                @$this->sql['triggers'] .= '-- TRIGGERS (' . count($triggers) . ')' . str_repeat(PHP_EOL, 2);
                $this->sql['triggers'] .= 'DELIMITER //' . PHP_EOL;
                foreach ($triggers as $trigger) {
                    $query = $this->getFirst('SHOW CREATE TRIGGER %s', [$trigger['Trigger']]);
                    $this->sql['triggers'] .= $query['SQL Original Statement'] . '//' . PHP_EOL;
                }
                $this->sql['triggers'] .= 'DELIMITER ;' . str_repeat(PHP_EOL, 5);
            }
        } catch (\Throwable $e) {
            Terminal::text('[color=red]Can not dump triggers.[/color]');
        }
    }

    private function dumpFunctions()
    {
        try {
            $functions = $this->getAll('SHOW FUNCTION STATUS WHERE Db = "%s"', [$this->dbname]);
            if (count($functions) > 0) {
                @$this->sql['functions'] .= '-- FUNCTIONS (' . count($functions) . ')' . str_repeat(PHP_EOL, 2);
                $this->sql['functions'] .= 'DELIMITER //' . PHP_EOL;
                foreach ($functions as $function) {
                    $query = $this->getFirst('SHOW CREATE FUNCTION %s', [$function['Name']]);
                    $this->sql['functions'] .= $query['Create Function'] . '//' . PHP_EOL;
                }
                $this->sql['functions'] .= 'DELIMITER ;' . str_repeat(PHP_EOL, 5);
            }
        } catch (\Throwable $e) {
            Terminal::text('[color=red]Can not dump functions.[/color]');
        }
    }

    private function dumpProcedures()
    {
        try {
            $procedures = $this->getAll('SHOW PROCEDURE STATUS WHERE Db = "%s"', [$this->dbname]);
            if (count($procedures) > 0) {
                @$this->sql['producers'] .= '-- PROCEDURES (' . count($procedures) . ')' . str_repeat(PHP_EOL, 2);
                $this->sql['producers'] .= 'DELIMITER //' . PHP_EOL;
                foreach ($procedures as $procedure) {
                    $query = $this->getFirst('SHOW CREATE PROCEDURE %s', [$procedure['Name']]);
                    $this->sql['producers'] .= $query['Create Procedure'] . '//' . PHP_EOL;
                }
                $this->sql['producers'] .= 'DELIMITER ;' . str_repeat(PHP_EOL, 5);
            }
        } catch (\Throwable $e) {
            Terminal::text('[color=red]Can not dump producers.[/color]');
        }
    }
}
