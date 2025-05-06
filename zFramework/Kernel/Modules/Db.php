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

    public static function begin()
    {
        self::connectDB(Terminal::$parameters['db'] ?? array_keys($GLOBALS['databases']['connections'])[0]);
        self::{Terminal::$commands[1]}();
    }

    private static function connectDB($db)
    {
        self::$db     = new FacadesDB($db);
        self::$dbname = self::$db->prepare('SELECT database() AS dbname')->fetch(\PDO::FETCH_ASSOC)['dbname'];
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

    public static function migrate()
    {
        $MySQL_defines = ['CURRENT_TIMESTAMP'];

        $migrations      = [];
        $path            = Terminal::$parameters['--path'] ?? null;
        $migrations_path = 'migrations' . ($path ? "/$path" : null);


        if (isset(Terminal::$parameters['--module'])) {
            # select one module migrations
            $module = BASE_PATH . ("/modules/" . Terminal::$parameters['--module']);
            if (!is_dir($module)) return Terminal::text("[color=red]You haven't a module like this.[/color]");
            foreach (self::recursiveScanMigrations("$module/$migrations_path") as $row) $migrations[] = $row;
            Terminal::text('[color=blue]You have selected a module: `' . Terminal::$parameters['--module'] . '`.[/color]');
            #
        } else if (in_array('--module', Terminal::$parameters)) {
            # select all modules migrations
            foreach (array_column(Run::findModules()::$modules, 'module') as $module) foreach (self::recursiveScanMigrations(BASE_PATH . ("/modules/$module") . "/$migrations_path") as $row) $migrations[] = $row;
            Terminal::text('[color=blue]All modules migrates selected.[/color]');
            #       
        } else {
            # default migrations
            foreach (self::recursiveScanMigrations(BASE_PATH . "/database/$migrations_path") as $row) $migrations[] = $row;
            # 
        }

        if (!count($migrations)) {
            Terminal::text("[color=red]You haven't a migration in `$migrations_path`.[/color]"); //  . ($path ? " [color=yellow]in `" . $path . "` folder[/color]" : null)
            return false;
        }

        foreach ($migrations as $migration) {
            // if (!in_array($migration, \zFramework\Run::$included)) \zFramework\Run::includer($migration);

            $class = str_replace(['.php', BASE_PATH, '/'], ['', '', '\\'], $migration);
            // control
            if (!class_exists($class)) {
                Terminal::text("[color=red]There are not a $class migrate class.[/color]");
                continue;
            }

            if (!isset($GLOBALS['databases']['connections'][$class::$db])) {
                Terminal::text("[color=red]" . $class::$db . " database is not exists.[/color]");
                continue;
            }

            # connect to model's database
            self::connectDB($class::$db);
            $columns = $class::columns();

            # setting prefix.
            if (isset($class::$prefix)) foreach ($columns as $name => $val) {
                unset($columns[$name]);
                $name = ($class::$prefix ? $class::$prefix . "_" : null) . $name;
                $columns[$name] = $val;
            }
            #

            # Setting consts
            $consts = config('model.consts');
            if (strlen($key = array_search('timestamps', $columns))) {
                unset($columns[$key]);
                $columns = array_merge($columns, [
                    $consts['updated_at'] => ['required', 'datetime', 'default:CURRENT_TIMESTAMP', 'onupdate'],
                    $consts['created_at'] => ['required', 'datetime', 'default:CURRENT_TIMESTAMP'],
                ]);
            }

            if (strlen($key = array_search('softDelete', $columns))) {
                unset($columns[$key]);
                $columns = array_merge($columns, [$consts['deleted_at'] => ['nullable', 'datetime', 'default']]);
            }
            #

            $storageEngine = $class::$storageEngine ?? 'InnoDB';
            $charset       = $class::$charset ?? null;
            $table         = $class::$table;
            //

            echo str_repeat(PHP_EOL, 2);
            Terminal::text("[color=green]`$table` migrating:[/color]");

            $drop_columns = [];

            # Reset Table.
            $migrate_fresh = in_array('--fresh', Terminal::$parameters) ?? false;
            if (!$migrate_fresh && !self::table_exists($table)) $migrate_fresh = true;

            if ($migrate_fresh) {
                Terminal::text('[color=blue]Info: Migrate forcing.[/color]');

                $init_column_name = "table_initilazing";
                try {
                    self::$db->prepare("DROP TABLE $table");
                } catch (\PDOException $e) {
                    // Terminal::text($e->getMessage(), 'red');
                }
                self::$db->prepare("CREATE TABLE $table ($init_column_name int DEFAULT 1 NOT NULL)" . ($charset ? " CHARACTER SET " . strtok($charset, '_') . " COLLATE $charset" : null));

                $drop_columns[] = $init_column_name;
            }
            #

            # detect dropped columns
            $tableColumns = self::$db->prepare("DESCRIBE $table")->fetchAll(\PDO::FETCH_COLUMN);
            foreach ($tableColumns as $column) if (!isset($columns[$column])) $drop_columns[] = $column;
            #

            # detect indexes and remove
            $indexes = array_column(self::$db->prepare("SHOW INDEX FROM $table")->fetchAll(\PDO::FETCH_ASSOC), 'Key_name');
            unset($indexes[array_search('PRIMARY', $indexes)]);
            foreach ($indexes as $index) {
                try {
                    self::$db->prepare("ALTER TABLE $table DROP INDEX " . $index);
                    Terminal::text("[color=yellow]-> `$index`[/color] [color=dark-gray]cleared index key[/color]");
                } catch (\PDOException $e) {
                    Terminal::text('[color=red]' . $e->getMessage() . '[/color]');
                }
            }
            if (count($indexes)) Terminal::text('[color=black]' . str_repeat('.', 30) . '[/color]');
            #

            # Migrate stuff
            $last_column = null;
            foreach ($columns as $column => $parameters) {
                $data = [
                    'type' => 'INT'
                ];

                try {
                    self::$db->prepare("ALTER TABLE $table DROP index $column;");
                } catch (\PDOException $e) {
                    // Terminal::text('Warning: ' . $e->getMessage(), 'yellow');
                }

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
                            $data['extras'][] = " ADD UNIQUE (`$column`) ";
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
                            $data['default'] = " DEFAULT" . (@$switch[1] ? (!in_array($switch[1], $MySQL_defines) ? ((is_numeric($switch[1]) ? " " . $switch[1] : " '" . addslashes($switch[1]) . "' ")) : (" " . $switch[1])) : ' NULL') . " ";
                            break;

                        case 'charset':
                            $data['charset'] =  " CHARACTER SET " . strtok($switch[1], '_') . " COLLATE " . $switch[1] . " ";
                            break;

                        case 'onupdate':
                            $data['default'] = $data['default'] . " ON UPDATE CURRENT_TIMESTAMP";
                            break;
                    }
                }

                $buildSQL = str_replace(['  ', ' ;'], [' ', ';'], ("ALTER TABLE $table ADD $column " . (@$data['type'] . @$data['charset'] . @$data['nullstatus'] . @$data['default'] . @$data['index']) . ($last_column ? " AFTER $last_column " : ' FIRST ') . (isset($data['extras']) ? ", " . implode(', ', $data['extras']) : null) . ";"));

                $while = ['loop' => true, 'continue' => false, 'status' => 0];
                while ($while['loop'] == true) {
                    try {
                        self::$db->prepare($buildSQL);
                        # insert edildiği anlamına geliyor.
                        if ($while['status'] == 0) $while['status'] = 1;
                        #
                        $while['loop'] = false;
                    } catch (\PDOException $e) {
                        switch ((string) $e->errorInfo['1']) {
                            case '1060':
                                $buildSQL = str_replace("$table ADD", "$table MODIFY", $buildSQL);
                                $while['status'] = 2;
                                break;

                            case '1068':
                                $while['status'] = 3;
                                $while['loop']   = false;
                                break;

                            default:
                                Terminal::text('[color=red]Unkown Error: ' . $e->getMessage() . '[/color]');
                                $while['loop'] = false;
                                continue 2;
                        }
                    }
                }

                $types = [3 => ['not changed.', 'dark-gray'], 1 => ['added', 'green'], 2 => ['modified', 'yellow']];
                Terminal::text("[color=" . $types[$while['status']][1] . "]-> `$column` " . $types[$while['status']][0] . "[/color]");

                $last_column = $column;
            }
            #

            foreach (array_unique($drop_columns) as $drop) {
                try {
                    self::$db->prepare("ALTER TABLE $table DROP COLUMN $drop");
                    Terminal::text("[color=yellow]Dropped column: $drop" . "[/color]");
                } catch (\PDOException $e) {
                    Terminal::text("[color=red]Error: Column is can not drop: $drop" . "[/color]");
                }
            }

            # update storage engine.
            self::$db->prepare("ALTER TABLE $table ENGINE = '$storageEngine'");
            Terminal::text("[color=yellow]`$table` storage engine is[/color] [color=blue]`$storageEngine`[/color]");

            Terminal::text("[color=green]`$table` migrate complete.[/color]");

            if ($migrate_fresh && in_array('oncreateSeeder', get_class_methods($class))) {
                Terminal::text("[color=green]Seeding.[/color]");
                $class::oncreateSeeder();
                Terminal::text("[color=green]Seeded.[/color]");
            }
        }

        if (in_array('--seed', Terminal::$parameters)) self::seed();
    }

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

    public static function backup()
    {
        $title = date('Y-m-d H-i-s');

        $backup = (new MySQLBackup(self::$db->db(), [
            'dir'      => base_path('/database/backups/' . self::$db->db),
            'save_as'  => $title,
            'compress' => in_array('--compress', Terminal::$parameters)
        ]))->backup();
        if ($backup) Terminal::text("[color=green](" . self::$dbname . ") " . self::$db->db . " backup ($title).[/color]");
        else Terminal::text("[color=red]Backup fail.[/color] [color=yellow]Check your database status. (if your database empty can not get backup)[/color]");
        return true;
    }

    public static function restore()
    {
        $backups = glob(base_path('database/backups/' . self::$db->db . '/*'));

        if (!count($backups)) return Terminal::text("[color=yellow](" . self::$dbname . ") " . self::$db->db . " haven't any backup.[/color]");

        Terminal::text("\n[color=yellow]*[/color] [color=blue]Backup list for `(" . self::$dbname . ") " . self::$db->db . "` database[/color]");
        foreach ($backups as $key => $name) Terminal::text("[color=yellow]" . ($key + 1) . "[/color]. [color=green]" . $name . "[/color]");
        Terminal::text("\n[color=yellow]*[/color] [color=blue]Select a backup[/color]");
        $backup = (int) readline('> ');

        if (!is_int($backup) || !isset($backups[$backup - 1])) return Terminal::clear()::text('[color=red]Selection is not acceptable.[/color]');

        Terminal::clear()::text("[color=yellow]Clearing...[/color]");
        $clear = self::$db->prepare("SELECT table_name FROM information_schema.tables WHERE table_schema = :DB_NAME", ['DB_NAME' => self::$dbname])->fetchAll(\PDO::FETCH_ASSOC);
        if (count($clear)) self::$db->prepare(implode(';', array_map(function ($table_name) {
            return "DROP TABLE IF EXISTS $table_name";
        }, array_column($clear, 'table_name'))));

        Terminal::clear()::text("[color=green]Cleared...[/color]");
        Terminal::text("[color=yellow]Restoring...[/color]");

        $backup = $backups[$backup - 1];

        // gz sıkıştırma için formül eklenecek.

        $data   = file_get_contents($backup);
        self::$db->prepare($data);
        Terminal::clear()::text("[color=green]Backup restored...[/color]");

        return true;
    }
}
