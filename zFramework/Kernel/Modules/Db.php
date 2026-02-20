<?php

namespace zFramework\Kernel\Modules;

use zFramework\Core\Facades\DB as FacadesDB;
use zFramework\Kernel\Helpers\MySQLBackup;
use zFramework\Kernel\Terminal;
use zFramework\Run;

class Db
{
    static $db;
    static $dbname;
    static $tables = null;
    static $all_modules = [];

    public static function begin($methods)
    {
        if (!in_array(@Terminal::$commands[1], $methods)) return Terminal::text('[color=red]You must select in method list: ' . implode(', ', $methods) . '[/color]');

        self::connectDB(Terminal::$parameters['db'] ?? array_keys($GLOBALS['databases']['connections'])[0]);
        self::$all_modules = array_column(Run::findModules(base_path('/modules'))::$modules, 'module');
        self::{Terminal::$commands[1]}();
    }

    private static function connectDB($db)
    {
        self::$db                = new FacadesDB($db);
        self::$db->ignoreAnalyze = true;
        self::$dbname            = self::$db->prepare('SELECT database() AS dbname')->fetch(\PDO::FETCH_ASSOC)['dbname'];
    }

    private static function table_exists($table = null)
    {
        if (!empty($table)) Terminal::$parameters['table'] = $table;

        if (!self::$tables) {
            $tables = self::$db->prepare("SELECT TABLE_NAME FROM information_schema.tables WHERE table_schema = :dbname", ['dbname' => self::$dbname])->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($tables as $key => $table) $tables[$key] = $table['TABLE_NAME'];
            self::$tables = $tables;
        }

        if (in_array(Terminal::$parameters['table'], self::$tables)) return true;
        return false;
    }

    private static function recursiveScanMigrations($path)
    {
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)) as $file) if ($file->isFile()) $files[] = $file->getPathname();
        return $files;
    }

    /**
     * Description: Migrate Database
     * @param --module={module_name} (optional)
     * @param --all (optional) (for all migrations do migrate)
     * @param --db (optional) (overwrite migrations $db parameter)
     * @param --path (optional)
     * @param --force (optional) (for migration forcefully)
     * @param --fresh (optional) (delete everything and again migration)
     * @param --seed (optional)
     */
    public static function migrate()
    {
        $MySQL_defines      = ['CURRENT_TIMESTAMP'];
        $migrations         = [];
        $path               = Terminal::$parameters['--path'] ?? null;
        $migrations_path    = 'migrations' . ($path ? "/$path" : null);
        $migrate_fresh      = in_array('--fresh', Terminal::$parameters) ?? false;
        $migrate_force      = in_array('--force', Terminal::$parameters) ?? false;
        $init_column_name   = "table_initilazing";
        $types              = [3 => ['not changed.', 'dark-gray'], 1 => ['added', 'green'], 2 => ['modified', 'yellow']];

        $scans = [BASE_PATH . "/database/$migrations_path"];

        if (in_array('--all', Terminal::$parameters)) foreach (self::$all_modules as $module) $scans[] = BASE_PATH . "/modules/$module/$migrations_path";
        else {
            if (isset(Terminal::$parameters['--module'])) {
                # select one module migrations
                $module = Terminal::$parameters['--module'];
                if (!in_array($module, self::$all_modules)) return Terminal::text("[color=red]You haven't a module like this.[/color]");
                $scans = ["$module/$migrations_path"];
                Terminal::text("[color=blue]You have selected a module: `$module`.[/color]");
                #
            } else if (in_array('--module', Terminal::$parameters)) {
                # select all modules migrations
                $scans = [];
                foreach (self::$all_modules as $module) $scans[] = BASE_PATH . ("/modules/$module") . "/$migrations_path";
                Terminal::text('[color=blue]All modules migrates selected.[/color]');
                #
            }
        }

        foreach ($scans as $scan) if (is_dir($scan)) $migrations = array_merge($migrations, self::recursiveScanMigrations($scan));

        if (!count($migrations)) {
            Terminal::text("[color=red]You haven't a migration in `" . implode(', ', $scans) . "`.[/color]");
            return false;
        }

        foreach ($migrations as $migration) {
            $last_modify     = filemtime($migration);
            $drop_columns    = [];
            $cleared_indexes = [];
            $class           = str_replace(['.php', BASE_PATH, '/'], ['', '', '\\'], $migration);

            // control
            if (!class_exists($class)) {
                Terminal::text("[color=red]There are not a $class migrate class.[/color]");
                continue;
            }

            $class = new $class;

            if (isset(Terminal::$parameters['--db'])) $class::$db = Terminal::$parameters['--db'];

            if (!isset($GLOBALS['databases']['connections'][$class::$db])) {
                Terminal::text("[color=red]" . $class::$db . " database is not exists.[/color]");
                continue;
            }

            # connect to model's database
            self::connectDB($class::$db);

            $last_migrate  = json_decode(@file_get_contents(self::$db->cache_dir . "/" . self::$dbname . "/last-migrate.json") ?? '[]', true);
            $columns       = $class::columns();
            $storageEngine = $class::$storageEngine ?? 'InnoDB';
            $charset       = $class::$charset ?? null;
            $table         = $class::$table;

            #region: Reset Table.
            $fresh = $migrate_fresh;
            if (!$fresh && !self::table_exists($table)) $fresh = true;
            if ($fresh) {
                Terminal::text('[color=blue]Info: Migrate forcing.[/color]');
                self::$db->prepare("DROP TABLE IF EXISTS $table");
                self::$db->prepare("CREATE TABLE $table ($init_column_name int DEFAULT 1 NOT NULL)" . ($charset ? " CHARACTER SET " . strtok($charset, '_') . " COLLATE $charset" : null));
                $drop_columns[] = $init_column_name;
            }
            #endregion

            if (!($fresh || $migrate_force) && strtotime($last_migrate['tables'][$table]['date'] ?? 0) > $last_modify) {
                Terminal::text("\n[color=green]`" . self::$dbname . ".$table` is up to date.[/color]");
                continue;
            }

            #region: detect indexes
            $indexes = [];
            try {
                foreach (self::$db->prepare("SHOW INDEX FROM $table")->fetchAll(\PDO::FETCH_ASSOC) as $index) if ($index['Key_name'] != 'PRIMARY') $indexes[$index['Key_name']] = $index['Key_name'];
            } catch (\PDOException $e) {
                Terminal::text("\n[color=yellow]`" . self::$dbname . ".$table` cannot access indexes.[/color]");
            }
            #endregion

            #region: setting prefix.
            if (isset($class::$prefix)) foreach ($columns as $name => $val) {
                unset($columns[$name]);
                $name = ($class::$prefix ? $class::$prefix . "_" : null) . $name;
                $columns[$name] = $val;
            }
            #endregion

            #region: Setting consts
            $consts = config('model.consts');
            if (strlen($key = array_search('timestamps', $columns))) {
                unset($columns[$key]);
                $columns = ($columns + [
                    $consts['updated_at'] => ['required', 'datetime', 'default:CURRENT_TIMESTAMP', 'onupdate'],
                    $consts['created_at'] => ['required', 'datetime', 'default:CURRENT_TIMESTAMP'],
                ]);
            }

            if (strlen($key = array_search('softDelete', $columns))) {
                unset($columns[$key]);
                $columns = ($columns + [$consts['deleted_at'] => [
                    'date' => ['nullable', 'datetime', 'default'],
                    'bool' => ['bool', 'default:1', 'required']
                ][config('model.deleted_at_type')]]);
            }
            #endregion
            //

            Terminal::text("\n[color=green]`" . self::$dbname . ".$table` migrating:[/color]");

            # detect dropped columns
            $tableColumns = self::$db->prepare("DESCRIBE $table")->fetchAll(\PDO::FETCH_COLUMN);
            foreach ($tableColumns as $column) if (!isset($columns[$column])) $drop_columns[] = $column;
            #

            $queue_index_list = [];

            #region: Migrate stuff
            $last_column = null;
            foreach ($columns as $column => $parameters) {
                $data = ['type' => 'INT'];

                foreach ($parameters as $parameter) {
                    $switch = explode(':', $parameter);
                    switch ($switch[0]) {
                        case 'primary':
                            $data['index'] = " PRIMARY KEY AUTO_INCREMENT ";
                            break;

                        case 'required':
                            $data['nullstatus'] = " NOT NULL ";
                            break;

                        case 'nullable':
                            $data['nullstatus'] = " NULL ";
                            break;

                        case 'unique':
                            $queue_index_list["unique_" . (isset($switch[1]) ? $switch[1] : $column)][] = $column;
                            break;

                        case 'index':
                            $queue_index_list["idx_" . (isset($switch[1]) ? $switch[1] : $column)][] = $column;
                            break;

                        # String: start
                        case 'text':
                            $data['type'] = " TEXT ";
                            break;

                        case 'longtext':
                            $data['type'] = " LONGTEXT ";
                            break;

                        case 'varchar':
                            $data['type'] = " VARCHAR(" . ($switch[1] ?? 255) . ") ";
                            break;

                        case 'char':
                            $data['type'] = " CHAR(" . ($switch[1] ?? 50) . ") ";
                            break;

                        case 'json':
                            $data['type'] = " JSON ";
                            break;
                        # String: end

                        # INT: start
                        case 'bigint':
                            $data['type'] = " BIGINT ";
                            break;

                        case 'int':
                            $data['type'] = " INT ";
                            break;

                        case 'smallint':
                            $data['type'] = " SMALLINT ";
                            break;

                        case 'tinyint':
                            $data['type'] = " TINYINT ";
                            break;

                        case 'bool':
                            $data['type'] = " TINYINT(1) ";
                            break;

                        case 'decimal':
                            $data['type'] = " DECIMAL ";
                            break;

                        case 'float':
                            $data['type'] = " FLOAT ";
                            break;

                        case 'real':
                            $data['type'] = " REAL ";
                            break;

                        # INT: end

                        # Date: start
                        case 'date':
                            $data['type'] = " DATE ";
                            break;

                        case 'datetime':
                            $data['type'] = " DATETIME ";
                            break;

                        case 'time':
                            $data['type'] = " TIME ";
                            break;
                        # Date: end

                        case 'default':
                            $data['default'] = " DEFAULT" . ((isset($switch[1]) && strlen($switch[1])) ? (!in_array($switch[1], $MySQL_defines) ? ((is_numeric($switch[1]) ? " " . $switch[1] : " '" . addslashes($switch[1]) . "' ")) : (" " . $switch[1])) : ' NULL') . " ";
                            break;

                        case 'charset':
                            $data['charset'] =  " CHARACTER SET " . strtok($switch[1], '_') . " COLLATE " . $switch[1] . " ";
                            break;

                        case 'onupdate':
                            $data['default'] = $data['default'] . " ON UPDATE CURRENT_TIMESTAMP";
                            break;
                    }
                }

                if ($fresh || $migrate_force) $column_need_update = true;
                else $column_need_update = !isset($last_migrate['tables'][$table]['columns'][$column]['data']) || $last_migrate['tables'][$table]['columns'][$column]['data'] != $data;

                $result = ['loop' => true, 'status' => 0];
                if ($column_need_update) {
                    $buildSQL = str_replace(['  ', ' ;'], [' ', ';'], ("ALTER TABLE $table ADD $column " . (@$data['type'] . @$data['charset'] . @$data['nullstatus'] . @$data['default'] . @$data['index']) . ($last_column ? " AFTER $last_column " : ' FIRST ') . (isset($data['extras']) ? ", " . implode(', ', $data['extras']) : null) . ";"));
                    while ($result['loop'] == true) {
                        try {
                            self::$db->prepare($buildSQL);
                            # insert edildiği anlamına geliyor.
                            if ($result['status'] == 0) $result['status'] = 1;
                            #
                            $result['loop'] = false;
                        } catch (\PDOException $e) {
                            switch ((string) $e->errorInfo['1']) {
                                case '1060':
                                    $buildSQL = str_replace("$table ADD", "$table MODIFY", $buildSQL);
                                    $result['status'] = 2;
                                    break;

                                case '1068':
                                    $result['status'] = 3;
                                    $result['loop']   = false;
                                    break;

                                default:
                                    Terminal::text('[color=red]Unkown Error: ' . $e->getMessage() . '[/color]');
                                    $result['loop'] = false;
                                    continue 2;
                            }
                        }
                    }
                } else {
                    $result['status'] = 3;
                    $result['loop']   = false;
                }

                $migrate_diffs = [];
                if ($result['status'] == 2) $migrate_diffs = array_diff($last_migrate['tables'][$table]['columns'][$column]['data'] ?? [], $data);

                Terminal::text(
                    "[color=" . $types[$result['status']][1] . "]-> `$column` " . $types[$result['status']][0] . "[/color]" .
                        (count($migrate_diffs) ? " [color=dark-gray]diff:" . implode(",", $migrate_diffs) . "[/color]" : null)
                );

                $last_migrate['tables'][$table]['date']             = date('Y-m-d H:i:s');
                $last_migrate['tables'][$table]['columns'][$column] = ['result' => ['status' => $result['status'], 'message' => $types[$result['status']][0]], 'data' => $data];
                $last_column = $column;
            }
            #endregion

            #region: index management
            foreach ($indexes as $index) try {
                self::$db->prepare("ALTER TABLE $table DROP INDEX $index");
                $cleared_indexes[] = $index;
            } catch (\Throwable $e) {
                Terminal::text('[color=red]ERR: ' . $e->getMessage() . '[/color]');
            }

            if (count($cleared_indexes)) {
                Terminal::text("[color=dark-gray]" . str_repeat('.', 40) . "[/color]");
                foreach ($cleared_indexes as $index) Terminal::text("[color=yellow]-> `$index`[/color] [color=dark-gray]cleared index key[/color]");
                Terminal::text("[color=dark-gray]" . str_repeat('.', 40) . "[/color]");
            }

            foreach ($queue_index_list as $index_key => $index_columns) try {
                self::$db->prepare("CREATE " . (str_starts_with($index_key, 'unique_') ? 'UNIQUE' : '') . " INDEX `" . substr($index_key, 0, 60) . "` ON `$table`(`" . implode('`, `', $index_columns) . "`);");
                Terminal::text("[color=green]-> added index key `$index_key`[/color][color=dark-gray](" . implode(', ', $index_columns) . ")[/color]");
            } catch (\Throwable $e) {
                Terminal::text('[color=red]ERR: ' . $e->getMessage() . '[/color]');
            }
            #endregion

            #region: drop columns
            foreach (array_unique($drop_columns) as $drop) try {
                self::$db->prepare("ALTER TABLE $table DROP COLUMN $drop");
                Terminal::text("[color=yellow]Dropped column: $drop" . "[/color]");
            } catch (\PDOException $e) {
                Terminal::text("[color=red]Error: Column is can not drop: $drop" . "[/color]");
            }
            #endregion

            #region: update storage engine.
            self::$db->prepare("ALTER TABLE $table ENGINE = '$storageEngine'");
            Terminal::text("[color=yellow]`" . self::$dbname . ".$table` storage engine is[/color] [color=blue]`$storageEngine`[/color]");
            #endregion

            Terminal::text("[color=green]`" . self::$dbname . ".$table` migrate complete.[/color]");
            if ($fresh && in_array('oncreateSeeder', get_class_methods($class))) {
                Terminal::text("\n[color=green]`" . self::$dbname . ".$table` Oncreate seeder.[/color]");
                Terminal::text("-> [color=green]Seeding.[/color]", true);
                $class::oncreateSeeder($class::$db);
                Terminal::text("-> [color=green]Seeded.[/color]", true);
            }

            @unlink(self::$db->cache_dir . "/" . self::$dbname . "/scheme.json");
            file_put_contents2(self::$db->cache_dir . "/" . self::$dbname . "/last-migrate.json", json_encode(['date' => date('Y-m-d H:i:s')] + $last_migrate, JSON_UNESCAPED_UNICODE));
        }

        if (in_array('--seed', Terminal::$parameters)) self::seed();
    }

    /**
     * Description: Seeder
     * @param --db (optional) (ifnull = Get first DB KEY)
     */
    public static function seed()
    {
        $seeders = glob(BASE_PATH . '/database/seeders/*.php');
        if (!count($seeders)) return Terminal::text("[color=red]You haven't any seeder.[/color]");
        foreach ($seeders as $inc) {
            $className = ucfirst(str_replace(['.php', BASE_PATH, '/'], ['', '', '\\'], $inc));
            (new $className())->destroy()->seed();
            Terminal::text("[color=green]$className seeded.[/color]");
        }

        return true;
    }

    /**
     * Description: Backup database
     * @param --db (optional) (ifnull = Get first DB KEY)
     * @param --compress (optional)
     */
    public static function backup()
    {
        $title = date('Y-m-d~H-i-s');
        (new MySQLBackup(self::$db, [
            'dir'      => base_path('/database/backups'),
            'save_as'  => $title,
            'compress' => in_array('--compress', Terminal::$parameters),
            'separate' => in_array('--separate', Terminal::$parameters)
        ]))->backup();
        return true;
    }

    /**
     * Description: Restore Backup
     * @param --db (optional) (ifnull = Get first DB KEY)
     */
    public static function restore()
    {
        $backups = glob(base_path('database/backups/' . self::$dbname . '/*'));

        if (!count($backups)) return Terminal::text("[color=yellow](" . self::$dbname . ") " . self::$db->db . " haven't any backup.[/color]");

        Terminal::text("\n[color=yellow]*[/color] [color=blue]Backup list for `" . self::$dbname . "` database[/color]");
        $list = [];
        foreach ($backups as $key => $name) {
            Terminal::text("[color=yellow]" . ($key + 1) . "[/color]. [color=green]" . basename($name) . "[/color]");
            $list[$key] = glob("$name/*");
            if (!count($list[$key])) Terminal::text(" -> [color=dark-gray]this backup is empty[/color]");
            else foreach ($list[$key] as $file) Terminal::text(" -> [color=yellow]" . basename($file) . "[/color]");
            Terminal::text("");
        }
        Terminal::text("\n[color=yellow]*[/color] [color=blue]Select a backup[/color]");
        $backup = (int) readline('> ');

        if (!is_int($backup) || !isset($backups[$backup - 1])) return Terminal::clear()::text('[color=red]Selection is not acceptable.[/color]');

        $backup = $list[$backup - 1];
        if (!count($backup)) return Terminal::text("[color=dark-gray]this backup is empty[/color]", true);

        Terminal::clear()::text("[color=yellow]Database's tables is dropping...[/color]", true);
        $clear = self::$db->prepare("SELECT table_name FROM information_schema.tables WHERE table_schema = :DB_NAME", ['DB_NAME' => self::$dbname])->fetchAll(\PDO::FETCH_ASSOC);
        if (count($clear)) self::$db->prepare(implode(';', array_map(fn($table_name) => "DROP TABLE IF EXISTS $table_name", array_column($clear, 'table_name'))));

        Terminal::text("[color=green]Tables dropped...[/color]", true);
        Terminal::text("[color=yellow]Backup restoring...[/color]", true);

        foreach ($backup as $file) {
            $data = file_get_contents($file);
            if (str_ends_with($file, '.sql.gz')) $data = gzdecode($data);
            // self::$db->connection()->exec($data);
            // Terminal::text("[color=green]" . basename($file) . " backup restored... (" . substr_count($data, ';') . " queries executed)[/color]", true);
            $queries   = explode(";", $data);
            $totalRows = 0;
            foreach ($queries as $sql) {
                if (trim($sql) === '') continue;

                try {
                    $totalRows += self::$db->connection()->exec($sql);
                } catch (\Throwable $e) {
                    Terminal::text('[color=red]ERR: ' . $e->getMessage() . '[/color]');
                }
            }

            Terminal::text("[color=green]" . basename($file) . " restored... (" . count($queries) . " queries, ~$totalRows rows affected)[/color]", true);
        }

        return true;
    }
}
