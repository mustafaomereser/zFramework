<?php

namespace zFramework\Kernel\Modules;

use zFramework\Core\Facades\DB as FacadesDB;
use zFramework\Kernel\Helpers\Ask;
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

        # --db is what the docblocks advertise and what parseCommands() stores, dashes
        # and all; the bare key is kept so anything already typed that way still works.
        self::connectDB(Terminal::$parameters['--db'] ?? Terminal::$parameters['db'] ?? array_keys($GLOBALS['databases']['connections'])[0]);
        self::$all_modules = array_column(Run::findModules(base_path('/modules'))::$modules, 'module');
        self::{Terminal::$commands[1]}();
    }

    /**
     * PDO driver name of the current connection - the migration speaks two
     * dialects, and this is how it picks (see pg()).
     */
    private static ?string $driver = null;

    private static function pg(): bool
    {
        return self::$driver === 'pgsql';
    }

    private static function connectDB($db)
    {
        self::$db                = new FacadesDB($db);
        self::$db->ignoreAnalyze = true;
        self::$driver            = self::$db->connection()->getAttribute(\PDO::ATTR_DRIVER_NAME);

        $previous     = self::$dbname;
        self::$dbname = self::$db->prepare(self::pg() ? 'SELECT current_database() AS dbname' : 'SELECT database() AS dbname')->fetch(\PDO::FETCH_ASSOC)['dbname'];

        # $tables lists what exists in the database we are connected to, so it stops
        # being an answer the moment that changes. migrate() reconnects per migration
        # class, and a stale list reported a table missing - which is how a populated
        # table in the second database met DROP TABLE IF EXISTS without --fresh.
        # Compared rather than cleared: one query per database, not per migration.
        if ($previous !== self::$dbname) self::$tables = null;
    }

    /**
     * A column definition as ALTER COLUMN statements - PostgreSQL has no
     * MODIFY; type, nullability and default each travel alone. USING makes
     * the type change explicit, so int-to-text and back both work.
     *
     * @param string $table
     * @param string $column
     * @param array  $data The definition the switch above built.
     * @return array
     */
    private static function pgModify(string $table, string $column, array $data): array
    {
        $statements = [];

        if ($type = trim((string) @$data['type'])) $statements[] = "ALTER TABLE $table ALTER COLUMN $column TYPE $type USING $column::$type";

        if (isset($data['nullstatus'])) $statements[] = "ALTER TABLE $table ALTER COLUMN $column " . (str_contains($data['nullstatus'], 'NOT') ? 'SET NOT NULL' : 'DROP NOT NULL');

        if (isset($data['default'])) {
            $default = trim(preg_replace('/^\s*DEFAULT\s*/i', '', $data['default']));
            $statements[] = "ALTER TABLE $table ALTER COLUMN $column " . ($default === '' || strtoupper($default) === 'NULL' ? 'DROP DEFAULT' : "SET DEFAULT $default");
        }

        return $statements;
    }

    /**
     * Compared the way the server reports them: `utf8` is utf8mb3 on newer servers.
     */
    private static function collationName(string $collation): string
    {
        return preg_replace('/^utf8_/', 'utf8mb3_', strtolower($collation));
    }

    /**
     * May text in this character set be rewritten into that one? The same set keeps
     * its bytes; ascii and utf8mb3 are subsets of utf8mb4. Anything else can turn
     * characters into `?` without an error, whatever sql_mode says.
     */
    private static function charsetSafe(string $from, string $to): bool
    {
        $from = strtok(self::collationName($from . '_'), '_');
        $to   = strtok(self::collationName($to . '_'), '_');

        return $from === $to || ($to === 'utf8mb4' && in_array($from, ['ascii', 'utf8mb3']));
    }

    /**
     * Character set and collation of a table's text columns, by lower-cased name.
     */
    private static function columnCharsets(string $table): array
    {
        $charsets = [];
        foreach (self::$db->prepare("SELECT COLUMN_NAME AS name, CHARACTER_SET_NAME AS charset, COLLATION_NAME AS collation FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = :dbname AND TABLE_NAME = :t AND COLLATION_NAME IS NOT NULL", ['dbname' => self::$dbname, 't' => $table])->fetchAll(\PDO::FETCH_ASSOC) as $row)
            $charsets[strtolower($row['name'])] = ['charset' => $row['charset'], 'collation' => $row['collation']];

        return $charsets;
    }

    /**
     * What differs between an existing table and its migration's collations, and
     * which of it may be converted. A change of character set that could lose data
     * lands in `refused`. Without a $charset only a column's own charset: counts.
     *
     * @return array{differs: string[], refused: string[], convert: array{columns: array<string, true>, default: bool}}
     */
    private static function charsetDifferences(string $table, ?string $charset, array $columns, ?string $prefix = null): array
    {
        $found = self::$db->prepare("SELECT TABLE_COLLATION AS collation FROM information_schema.TABLES WHERE TABLE_SCHEMA = :dbname AND TABLE_NAME = :t", ['dbname' => self::$dbname, 't' => $table])->fetch(\PDO::FETCH_ASSOC);
        $convert = ['columns' => [], 'default' => false];
        $differs = $refused = [];

        if ($charset && $found && self::collationName($found['collation']) !== self::collationName($charset)) {
            if (self::charsetSafe($found['collation'], $charset)) $convert['default'] = true;
            else $refused[] = "table default {$found['collation']} -> $charset";
            $differs[] = "table default {$found['collation']} -> $charset";
        }

        $current = self::columnCharsets($table);
        foreach ($columns as $name => $parameters) {
            if (!is_array($parameters)) continue;

            $text = false;
            $want = $charset;
            foreach ($parameters as $parameter) {
                $switch = explode(':', (string) $parameter);
                if (in_array($switch[0], ['text', 'longtext', 'varchar', 'char', 'uuid'])) $text = true;
                if ($switch[0] === 'charset' && isset($switch[1])) $want = $switch[1];
            }

            $name = strtolower(($prefix ? $prefix . "_" : '') . $name);
            if (!$want || !$text || !isset($current[$name]) || self::collationName($current[$name]['collation']) === self::collationName($want)) continue;

            $differs[] = "$name {$current[$name]['collation']} -> $want";
            if (self::charsetSafe($current[$name]['charset'], $want)) $convert['columns'][$name] = true;
            else $refused[] = "$name {$current[$name]['collation']} -> $want";
        }

        return ['differs' => $differs, 'refused' => $refused, 'convert' => $convert];
    }

    /**
     * Say what differs on the way through a migrate. Without --convert-charset that
     * is all it does; with it, the answer is what migrate() converts.
     *
     * @return array{columns: array<string, true>, default: bool, pending?: bool}
     */
    private static function charsetDrift(string $table, string $charset, array $columns, ?string $prefix, bool $apply): array
    {
        ['differs' => $differs, 'refused' => $refused, 'convert' => $convert] = self::charsetDifferences($table, $charset, $columns, $prefix);

        if (!$differs) return $convert;

        if (!$apply) Terminal::text("[color=yellow]`" . self::$dbname . ".$table` charset differs from its migration: " . implode(', ', $differs) . ".[/color]");

        if ($refused) Terminal::text("[color=red]`" . self::$dbname . ".$table` refused, the character set would change and text could be lost: " . implode(', ', $refused) . ". Convert it by hand after a backup.[/color]");

        # Reported only: `pending` is what the reminder at the end of the run counts.
        return $apply ? $convert : ['columns' => [], 'default' => false, 'pending' => $convert['columns'] || $convert['default']];
    }

    /**
     * The CHARACTER SET / COLLATE part of a column's ADD or MODIFY.
     *
     * Left out, MODIFY moves the column to the table's default - so a migration
     * that only changed a default also changed the column's collation, and rewrote
     * its text if the default was another character set. What the migration asks
     * for is written; a change that could lose data is not, and the column stays
     * exactly as it is.
     */
    private static function charsetClause(string $table, string $column, array $data, ?string $charset, array $current): string
    {
        if (!preg_match('/\b(VARCHAR|CHAR|TEXT|LONGTEXT)\b/i', (string) ($data['type'] ?? ''))) return (string) ($data['charset'] ?? '');

        $want = preg_match('/COLLATE (\S+)/', (string) ($data['charset'] ?? ''), $m) ? $m[1] : $charset;
        $have = $current[strtolower($column)] ?? null;

        if ($have && $want && !self::charsetSafe($have['charset'], $want)) {
            Terminal::text("[color=red]-> `$column` stays {$have['collation']}: $want would change its character set and text could be lost.[/color]");
            $want = null;
        }

        if ($want) return " CHARACTER SET " . strtok($want, '_') . " COLLATE $want ";

        return $have ? " CHARACTER SET {$have['charset']} COLLATE {$have['collation']} " : '';
    }

    private static function table_exists($table = null)
    {
        if (!empty($table)) Terminal::$parameters['table'] = $table;

        if (!self::$tables) {
            # PostgreSQL scopes information_schema by SCHEMA, not database name.
            $tables = self::pg()
                ? self::$db->prepare('SELECT table_name AS "TABLE_NAME" FROM information_schema.tables WHERE table_schema = current_schema()')->fetchAll(\PDO::FETCH_ASSOC)
                : self::$db->prepare("SELECT TABLE_NAME FROM information_schema.tables WHERE table_schema = :dbname", ['dbname' => self::$dbname])->fetchAll(\PDO::FETCH_ASSOC);
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
     * A migration's columns() as the table will have them: prefix applied, the
     * timestamps and softDelete shorthands expanded.
     */
    private static function declaredColumns(object $class, array $columns): array
    {
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
            ][config('model.deleted_at_type') ?? 'date']]);
        }
        #endregion

        return $columns;
    }

    /**
     * Description: List what the database and the migrations disagree on. Changes nothing without --prune.
     * Usage: php terminal db diff [--prune] [--db=default] [--path=sub]
     * @param --prune            (optional) after the list and a Y, drop the tables and columns with no migration
     * @param --db={key}         (optional) compare only this connection, all migrations against it
     * @param --path={subdir}    (optional) sub-path inside migrations folder
     */
    public static function diff()
    {
        $path  = Terminal::$parameters['--path'] ?? null;
        $sub   = 'migrations' . ($path ? "/$path" : null);
        $files = [];

        # Every module, always: a table a module migrates is not an orphan just
        # because the module was not asked about.
        foreach (array_merge([BASE_PATH . "/database/$sub"], array_map(fn($module) => BASE_PATH . "/modules/$module/$sub", self::$all_modules)) as $scan)
            if (is_dir($scan)) $files = array_merge($files, self::recursiveScanMigrations($scan));

        $prune    = in_array('--prune', Terminal::$parameters);
        $unread   = false;
        $declared = [];
        foreach ($files as $file) {
            if (!str_ends_with($file, '.php')) continue;

            $class = str_replace(['.php', BASE_PATH, '/'], ['', '', '\\'], $file);
            if (!class_exists($class)) {
                Terminal::text("[color=red]There are not a $class migrate class.[/color]");
                $unread = true;
                continue;
            }

            $class = new $class;
            if (isset(Terminal::$parameters['--db'])) $class::$db = Terminal::$parameters['--db'];
            # Added, not replaced: two migrations of one table both declare what they declare.
            $declared[$class::$db][$class::$table] = ($declared[$class::$db][$class::$table] ?? []) + array_change_key_case(self::declaredColumns($class, $class::columns()), CASE_LOWER);
            $charsets[$class::$db][$class::$table] = $class::$charset ?? null;
        }

        # A connection nothing migrates has nothing to compare with: every table on it
        # would be listed, and it may well belong to something else entirely.
        if (!$declared) return Terminal::text("[color=red]You haven't a migration to compare with.[/color]");

        # Two connection keys can reach one database, and a table the other key
        # migrates has a migration all the same.
        $migrated = [];
        foreach ($declared as $connection => $tables) if (isset($GLOBALS['databases']['connections'][$connection])) {
            self::connectDB($connection);
            foreach ($tables as $table => $columns) $migrated[self::$dbname][strtolower($table)] = true;
        }

        # A migration that could not be read makes its table look unneeded.
        if ($prune && $unread) {
            Terminal::text('[color=red]--prune refused: a migration above could not be read, and its table would look unneeded. Nothing will be dropped.[/color]');
            $prune = false;
        }

        foreach ($declared as $connection => $tables) {
            if (!isset($GLOBALS['databases']['connections'][$connection])) {
                Terminal::text("[color=red]$connection database is not exists.[/color]");
                continue;
            }

            self::connectDB($connection);
            self::table_exists(array_key_first($tables));

            # Under --path only part of the migrations were read, so the rest of the
            # tables say nothing about having one.
            # Without regard to case: on lower_case_table_names=1 the server answers
            # `users` for a migration that says `Users`.
            $orphanTables = $path ? [] : array_values(array_filter(self::$tables, fn($table) => !isset($migrated[self::$dbname][strtolower($table)])));
            $orphans = $missingTables = $missingColumns = $charsetDiffers = $charsetRefused = [];
            foreach ($tables as $table => $columns) {
                if (!in_array($table, self::$tables)) {
                    $missingTables[] = $table;
                    continue;
                }

                $existing = self::pg()
                    ? self::$db->prepare('SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = :t', ['t' => $table])->fetchAll(\PDO::FETCH_COLUMN)
                    : self::$db->prepare("DESCRIBE $table")->fetchAll(\PDO::FETCH_COLUMN);

                foreach ($existing as $column) if (!isset($columns[strtolower($column)])) $orphans[] = [$table, $column];
                foreach (array_diff(array_keys($columns), array_map('strtolower', $existing)) as $column) $missingColumns[] = "$table.$column";

                if (!self::pg()) {
                    $charsetState = self::charsetDifferences($table, $charsets[$connection][$table], $columns);
                    foreach (array_diff($charsetState['differs'], $charsetState['refused']) as $item) $charsetDiffers[] = "$table: $item";
                    foreach ($charsetState['refused'] as $item) $charsetRefused[] = "$table: $item";
                }
            }

            Terminal::text("\n[color=green]`" . self::$dbname . "` ($connection):[/color]");

            $sections = [
                ['Tables with no migration', 'db migrate leaves them alone', 'yellow', $orphanTables],
                ['Columns with no migration', 'the next db migrate of that table DROPS them', 'red', array_map(fn($orphan) => implode('.', $orphan), $orphans)],
                ['Tables not in the database', 'db migrate creates them', 'blue', $missingTables],
                ['Columns not in the database', 'db migrate adds them', 'blue', $missingColumns],
                ['Charset differs', 'db migrate --convert-charset converts them', 'yellow', $charsetDiffers],
                ['Charset differs, refused', 'the character set would change and text could be lost: by hand, after a backup', 'red', $charsetRefused],
            ];

            if (!$orphanTables && !$orphans && !$missingTables && !$missingColumns && !$charsetDiffers && !$charsetRefused) Terminal::text('[color=dark-gray]No difference.[/color]');

            foreach ($sections as [$title, $note, $color, $items]) if ($items) {
                Terminal::text("[color=$color]{$title}[/color] [color=dark-gray]({$note})[/color]");
                foreach ($items as $item) Terminal::text("[color=$color]-> {$item}[/color]");
            }

            if (!$orphanTables && !$orphans) continue;

            if (!in_array('--prune', Terminal::$parameters)) {
                Terminal::text('[color=dark-gray]db diff --prune drops the tables and columns with no migration, after asking.[/color]');
                continue;
            }

            if ($prune) self::prune($orphanTables, $orphans);
        }
    }

    /**
     * Asks, naming the database, then drops. There is no undo, so there is no flag
     * that skips the question: where nobody can answer, nothing is dropped.
     *
     * @param array $tables  Table names.
     * @param array $columns [table, column] pairs.
     */
    private static function prune(array $tables, array $columns): void
    {
        if (in_array('--web', Terminal::$parameters) || !function_exists('readline')) {
            Terminal::text('[color=red]--prune asks first, and nobody can answer here. Nothing was dropped: run it in a terminal.[/color]');
            return;
        }

        # information_schema lists views as tables, and DROP TABLE does not take one.
        $views = self::pg()
            ? self::$db->prepare("SELECT table_name FROM information_schema.tables WHERE table_schema = current_schema() AND table_type = 'VIEW'")->fetchAll(\PDO::FETCH_COLUMN)
            : self::$db->prepare("SELECT TABLE_NAME FROM information_schema.tables WHERE table_schema = :dbname AND table_type = 'VIEW'", ['dbname' => self::$dbname])->fetchAll(\PDO::FETCH_COLUMN);

        foreach (array_intersect($tables, $views) as $view) Terminal::text("[color=dark-gray]-> $view is a view, it stays.[/color]");
        $tables = array_values(array_diff($tables, $views));
        if (!$tables && !$columns) return;

        Terminal::text("\n[color=yellow]Take a backup first if you may want these back (db backup).[/color]");
        Ask::do('[color=red]Drop ' . count($tables) . ' table(s) and ' . count($columns) . ' column(s) from `' . self::$dbname . '`? This cannot be undone.[/color]', function () use ($tables, $columns) {
            $q    = self::pg() ? '"' : '`';
            $name = fn(string $identifier) => $q . str_replace($q, $q . $q, $identifier) . $q;

            foreach ($columns as [$table, $column]) try {
                self::$db->prepare('ALTER TABLE ' . $name($table) . ' DROP COLUMN ' . $name($column));
                Terminal::text("[color=yellow]Dropped column: {$table}.{$column}[/color]");
            } catch (\Throwable $e) {
                Terminal::text("[color=red]Column stays: $table.$column - " . $e->getMessage() . '[/color]');
            }

            # A table another one points at can only go after it, and nothing says which
            # comes first: round again while a round dropped something. Foreign key
            # checks stay on, so a table something still points at stays too.
            $errors = [];
            do {
                $dropped = false;
                foreach ($tables as $key => $table) try {
                    self::$db->prepare('DROP TABLE ' . $name($table));
                    Terminal::text("[color=yellow]Dropped table: {$table}[/color]");
                    unset($tables[$key], $errors[$table]);
                    $dropped = true;
                } catch (\Throwable $e) {
                    $errors[$table] = $e->getMessage();
                }
            } while ($dropped && $tables);

            foreach ($errors as $table => $error) Terminal::text("[color=red]Table stays: {$table} - {$error}[/color]");

            self::$tables = null;
            self::$db->forgetScheme();
        });
    }

    /**
     * Description: Migrate database
     * Usage: php terminal db migrate [--fresh] [--force] [--convert-charset] [--seed] [--all] [--module=blog] [--db=default] [--path=sub]
     * @param --fresh            (optional) drop all tables and re-migrate
     * @param --force            (optional) force migration even if unchanged
     * @param --convert-charset  (optional) bring existing text columns and the table default to the migration's collation
     * @param --seed             (optional) run seeders after migrate
     * @param --all              (optional) include all module migrations
     * @param --module={name}    (optional) migrate only this module
     * @param --db={key}         (optional) override db connection key
     * @param --path={subdir}    (optional) sub-path inside migrations folder
     */
    public static function migrate()
    {
        $MySQL_defines      = ['CURRENT_TIMESTAMP'];
        $migrations         = [];
        $path               = Terminal::$parameters['--path'] ?? null;
        $migrations_path    = 'migrations' . ($path ? "/$path" : null);
        $migrate_fresh      = in_array('--fresh', Terminal::$parameters) ?? false;
        $migrate_force      = in_array('--force', Terminal::$parameters) ?? false;
        $migrate_convert    = in_array('--convert-charset', Terminal::$parameters);
        $charset_pending    = 0;
        $init_column_name   = "table_initilazing";
        $types              = [3 => ['not changed.', 'dark-gray'], 1 => ['added', 'green'], 2 => ['modified', 'yellow'], 0 => ['FAILED - see the error above', 'red']];

        $scans = [BASE_PATH . "/database/$migrations_path"];

        if (in_array('--all', Terminal::$parameters)) foreach (self::$all_modules as $module) $scans[] = BASE_PATH . "/modules/$module/$migrations_path";
        else {
            if (isset(Terminal::$parameters['--module'])) {
                # select one module migrations
                #
                # Matched without regard to case and then used as the directory is
                # actually spelled: the container is lowercase and the module is not,
                # so `--module=blog` is what gets typed and `modules/Blog` is what is
                # there. BASE_PATH was missing from the scan path too, while both
                # sibling branches have it, so this looked for `blog/migrations` beside
                # the working directory and reported the module had no migrations.
                $module   = Terminal::$parameters['--module'];
                $resolved = null;
                foreach (self::$all_modules as $known) if (strcasecmp($known, (string) $module) === 0) $resolved = $known;

                if (!$resolved) return Terminal::text("[color=red]You haven't a module like this.[/color]");

                $module = $resolved;
                $scans  = [BASE_PATH . "/modules/$module/$migrations_path"];
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
            $touch_columns   = [];
            $table_failed    = false;
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
                self::$db->prepare("CREATE TABLE $table ($init_column_name int DEFAULT 1 NOT NULL)" . ($charset && !self::pg() ? " CHARACTER SET " . strtok($charset, '_') . " COLLATE $charset" : null));
                $drop_columns[] = $init_column_name;
            }
            #endregion

            # Before the up-to-date check: a table whose migration has not changed can
            # still have drifted, and that is the table --convert-charset is run for.
            $convert = !$fresh && $charset && !self::pg() ? self::charsetDrift($table, $charset, $columns, $class::$prefix ?? '', $migrate_convert) : ['columns' => [], 'default' => false];
            if ($convert['pending'] ?? false) $charset_pending++;

            if (!($fresh || $migrate_force || $convert['columns'] || $convert['default']) && strtotime($last_migrate['tables'][$table]['date'] ?? 0) > $last_modify) {
                Terminal::text("\n[color=green]`" . self::$dbname . ".$table` is up to date.[/color]");
                continue;
            }

            #region: detect indexes
            $indexes = [];
            try {
                if (self::pg()) foreach (self::$db->prepare("SELECT indexname FROM pg_indexes WHERE schemaname = current_schema() AND tablename = :t", ['t' => $table])->fetchAll(\PDO::FETCH_COLUMN) as $index) {
                    if (!str_ends_with($index, '_pkey')) $indexes[$index] = $index;
                } else foreach (self::$db->prepare("SHOW INDEX FROM $table")->fetchAll(\PDO::FETCH_ASSOC) as $index) if ($index['Key_name'] != 'PRIMARY') $indexes[$index['Key_name']] = $index['Key_name'];
            } catch (\PDOException $e) {
                Terminal::text("\n[color=yellow]`" . self::$dbname . ".$table` cannot access indexes.[/color]");
            }
            #endregion

            $columns = self::declaredColumns($class, $columns);

            Terminal::text("\n[color=green]`" . self::$dbname . ".$table` migrating:[/color]");

            # detect dropped columns
            # Case-insensitively, as MySQL names columns: `email` renamed to `Email`
            # in the migration is the same column, and comparing bytes put it on the
            # drop list - ADD failed (exists), MODIFY passed, DROP took the data.
            $tableColumns = self::pg()
                ? self::$db->prepare('SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = :t', ['t' => $table])->fetchAll(\PDO::FETCH_COLUMN)
                : self::$db->prepare("DESCRIBE $table")->fetchAll(\PDO::FETCH_COLUMN);
            $declared     = array_change_key_case($columns, CASE_LOWER);
            foreach ($tableColumns as $column) if (!isset($declared[strtolower($column)])) $drop_columns[] = $column;
            #

            $queue_index_list = [];
            $current_charsets = self::pg() ? [] : self::columnCharsets($table);

            #region: Migrate stuff
            $last_column = null;
            foreach ($columns as $column => $parameters) {
                $data = ['type' => 'INT'];

                foreach ($parameters as $parameter) {
                    $switch = explode(':', $parameter);
                    switch ($switch[0]) {
                        case 'primary':
                            # `primary`             -> PRIMARY KEY AUTO_INCREMENT (default)
                            # `primary:noincrement` -> PRIMARY KEY only, for uuid/char/natural keys
                            $data['index'] = " PRIMARY KEY " . (in_array(strtolower($switch[1] ?? ''), ['noincrement', 'noai', 'false']) ? null : "AUTO_INCREMENT ");
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

                        # Canonical 36-char textual uuid. Pair with `primary:noincrement`
                        # and `default:(UUID())` for a self-populating uuid key.
                        case 'uuid':
                            $data['type'] = " CHAR(36) ";
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
                            # `default:(EXPRESSION)` passes through verbatim: MySQL 8.0.13+ requires
                            # function defaults to be wrapped in parentheses, e.g. `default:(UUID())`.
                            if (isset($switch[1]) && str_starts_with($switch[1], '(')) $data['default'] = " DEFAULT " . implode(':', array_slice($switch, 1)) . " ";
                            else $data['default'] = " DEFAULT" . ((isset($switch[1]) && strlen($switch[1])) ? (!in_array($switch[1], $MySQL_defines) ? ((is_numeric($switch[1]) ? " " . $switch[1] : " '" . addslashes($switch[1]) . "' ")) : (" " . $switch[1])) : ' NULL') . " ";
                            break;

                        case 'charset':
                            $data['charset'] =  " CHARACTER SET " . strtok($switch[1], '_') . " COLLATE " . $switch[1] . " ";
                            break;

                        case 'onupdate':
                            # No ON UPDATE clause in PostgreSQL; a trigger written
                            # after the columns does the same job - see below.
                            if (self::pg()) $touch_columns[] = $column;
                            else $data['default'] = $data['default'] . " ON UPDATE CURRENT_TIMESTAMP";
                            break;
                    }
                }

                # One dialect in the migration files, two on the wire. The switch
                # above speaks MySQL; for PostgreSQL the finished definition is
                # translated in one place rather than forked case by case.
                if (self::pg()) {
                    $data['type'] = ' ' . strtr(trim((string) @$data['type']), [
                        'LONGTEXT'   => 'TEXT',
                        'TINYINT(1)' => 'SMALLINT',
                        'TINYINT'    => 'SMALLINT',
                        'DATETIME'   => 'TIMESTAMP',
                        # JSONB, not JSON: it is the indexed, binary form - the one
                        # anyone means when they ask PostgreSQL for json.
                        'JSON'       => 'JSONB',
                        'FLOAT'      => 'DOUBLE PRECISION',
                    ]) . ' ';
                    if (isset($data['index'])) $data['index'] = str_replace('AUTO_INCREMENT', 'GENERATED BY DEFAULT AS IDENTITY', $data['index']);
                    unset($data['charset']); # per-column charsets are a MySQL concept
                }

                # AUTO_INCREMENT is integer-only. A `primary` on a uuid/char/date column
                # would fail with 1063; drop the increment instead of breaking the migration.
                if (isset($data['index']) && (str_contains($data['index'], 'AUTO_INCREMENT') || str_contains($data['index'], 'IDENTITY')) && !preg_match('/\b(TINYINT|SMALLINT|MEDIUMINT|INT|BIGINT)\b/i', $data['type'])) $data['index'] = " PRIMARY KEY ";

                if ($fresh || $migrate_force || isset($convert['columns'][strtolower($column)])) $column_need_update = true;
                else $column_need_update = !isset($last_migrate['tables'][$table]['columns'][$column]['data']) || $last_migrate['tables'][$table]['columns'][$column]['data'] != $data;

                $result = ['loop' => true, 'status' => 0];
                if ($column_need_update) {
                    # Column position (AFTER/FIRST) is MySQL-only; PostgreSQL
                    # appends, and the order of columns() is cosmetic anyway.
                    $position = self::pg() ? ' ' : ($last_column ? " AFTER $last_column " : ' FIRST ');
                    $buildSQL = str_replace(['  ', ' ;'], [' ', ';'], ("ALTER TABLE $table ADD $column " . (@$data['type'] . (self::pg() ? null : self::charsetClause($table, $column, $data, $charset, $current_charsets)) . @$data['nullstatus'] . @$data['default'] . @$data['index']) . $position . (isset($data['extras']) ? ", " . implode(', ', $data['extras']) : null) . ";"));
                    while ($result['loop'] == true) {
                        try {
                            self::$db->prepare($buildSQL);
                            # insert edildiği anlamına geliyor.
                            if ($result['status'] == 0) $result['status'] = 1;
                            #
                            $result['loop'] = false;
                        } catch (\PDOException $e) {
                            # MySQL speaks in errno (errorInfo[1]), PostgreSQL in
                            # SQLSTATE (errorInfo[0]) - its errno slot holds a 7.
                            switch (self::pg() ? (string) $e->errorInfo[0] : (string) $e->errorInfo['1']) {
                                # Duplicate column: the column exists, apply the
                                # definition to it instead.
                                case '42701':
                                    # No MODIFY here: type, nullability and default are
                                    # their own ALTER COLUMN statements. The identity/
                                    # primary part is left alone - it already is one.
                                    foreach (self::pgModify($table, $column, $data) as $statement) self::$db->prepare($statement);
                                    $result['status'] = 2;
                                    $result['loop']   = false;
                                    break;

                                case '1060':
                                    $buildSQL = str_replace("$table ADD", "$table MODIFY", $buildSQL);
                                    $result['status'] = 2;
                                    break;

                                # "Multiple primary key defined": MODIFY re-declared PRIMARY KEY
                                # on the column that already is one. Once more without it -
                                # the rest of the definition still has to be applied. Mapped
                                # straight to "not changed" before, so a type change on the
                                # key column was never made and recorded as if it had been.
                                case '42P16': # multiple primary keys - PostgreSQL's 1068
                                case '1068':
                                    if (str_contains($buildSQL, 'PRIMARY KEY')) {
                                        $buildSQL = str_replace(' PRIMARY KEY ', ' ', $buildSQL);
                                        break;
                                    }
                                    $result['status'] = 3;
                                    $result['loop']   = false;
                                    break;

                                default:
                                    Terminal::text('[color=red]Unkown Error: ' . $e->getMessage() . '[/color]');
                                    # A MODIFY that failed had status 2 from the ADD before
                                    # it: printed "modified", recorded as done, never retried.
                                    $result['status'] = 0;
                                    $result['loop']   = false;
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

                # A failed column pins the table's date to the epoch: the next run must
                # not read it as up to date and skip the column that never landed.
                if ($result['status'] === 0) $table_failed = true;
                $last_migrate['tables'][$table]['date']             = $table_failed ? '1970-01-01 00:00:00' : date('Y-m-d H:i:s');
                $last_migrate['tables'][$table]['columns'][$column] = ['result' => ['status' => $result['status'], 'message' => $types[$result['status']][0]], 'data' => $result['status'] === 0 ? null : $data];
                $last_column = $column;
            }
            #endregion

            # Composite indexes in the order an index needs, which index:name can not
            # say: it groups columns in the order they are declared.
            if (isset($class::$indexes)) foreach ($class::$indexes as $index_name => $index_columns) $queue_index_list[$index_name] = (array) $index_columns;

            #region: index management
            foreach ($indexes as $index) try {
                self::$db->prepare(self::pg() ? "DROP INDEX $index" : "ALTER TABLE $table DROP INDEX $index");
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
                $q = self::pg() ? '"' : '`'; # identifier quote: backtick is MySQL-only
                self::$db->prepare("CREATE " . (str_starts_with($index_key, 'unique_') ? 'UNIQUE' : '') . " INDEX $q" . substr($index_key, 0, 60) . "$q ON $q$table$q($q" . implode("$q, $q", $index_columns) . "$q);");
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

            #region: table default collation
            # Metadata only, no row is touched. Last, and not after a failed column:
            # the table must not claim a collation its columns did not reach.
            if ($convert['default'] && !$table_failed) try {
                self::$db->prepare("ALTER TABLE $table DEFAULT CHARACTER SET " . strtok($charset, '_') . " COLLATE $charset");
                Terminal::text("[color=yellow]`" . self::$dbname . ".$table` default collation is[/color] [color=blue]`$charset`[/color]");
            } catch (\PDOException $e) {
                Terminal::text('[color=red]Error: ' . $e->getMessage() . '[/color]');
            }
            #endregion

            #region: update storage engine.
            if (!self::pg()) {
                self::$db->prepare("ALTER TABLE $table ENGINE = '$storageEngine'");
                Terminal::text("[color=yellow]`" . self::$dbname . ".$table` storage engine is[/color] [color=blue]`$storageEngine`[/color]");
            }
            #endregion

            #region: onupdate triggers (PostgreSQL)
            # MySQL writes ON UPDATE CURRENT_TIMESTAMP into the column;
            # PostgreSQL has no such clause, so each onupdate column gets a
            # BEFORE UPDATE trigger doing the same thing. Re-runnable: the
            # function is CREATE OR REPLACE, the trigger dropped first.
            foreach ($touch_columns ?? [] as $touch) {
                $fn = "zf_touch_{$table}_{$touch}";
                self::$db->prepare("CREATE OR REPLACE FUNCTION $fn() RETURNS trigger AS \$zf\$ BEGIN NEW.$touch := CURRENT_TIMESTAMP; RETURN NEW; END \$zf\$ LANGUAGE plpgsql");
                self::$db->prepare("DROP TRIGGER IF EXISTS $fn ON $table");
                self::$db->prepare("CREATE TRIGGER $fn BEFORE UPDATE ON $table FOR EACH ROW EXECUTE FUNCTION $fn()");
                Terminal::text("[color=green]-> trigger `$fn`[/color] [color=dark-gray]keeps $touch current on update[/color]");
            }
            #endregion

            Terminal::text("[color=green]`" . self::$dbname . ".$table` migrate complete.[/color]");

            # The table just changed shape, so every cached copy of the scheme is stale.
            # This has to happen BEFORE the seeder runs: seeders go through the model
            # layer and would otherwise write against the pre-migration column list.
            # (The APCu drop only reaches this CLI process - apcu.enable_cli is off by
            # default - but web processes are covered by the mtime check in DB::tables().)
            self::$db->forgetScheme();

            if ($fresh && in_array('oncreateSeeder', get_class_methods($class))) {
                Terminal::text("\n[color=green]`" . self::$dbname . ".$table` Oncreate seeder.[/color]");
                Terminal::text("-> [color=green]Seeding.[/color]", true);
                $class::oncreateSeeder($class::$db);
                Terminal::text("-> [color=green]Seeded.[/color]", true);
            }

            file_put_contents2(self::$db->cache_dir . "/" . self::$dbname . "/last-migrate.json", json_encode(['date' => date('Y-m-d H:i:s')] + $last_migrate, JSON_UNESCAPED_UNICODE));
        }

        if ($charset_pending) Terminal::text("\n[color=yellow]$charset_pending table(s) differ from their migration's charset and were not converted. Back up (`php terminal db backup`), then run again with --convert-charset.[/color]");

        if (in_array('--seed', Terminal::$parameters)) self::seed();
    }

    /**
     * Description: Run database seeders
     * Usage: php terminal db seed [--db=default]
     * @param --db={key}  (optional) db connection key, defaults to first
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
     * Description: Backup database to file
     * Usage: php terminal db backup [--compress] [--separate]
     * @param --compress  (optional) gzip compress the backup file
     * @param --separate  (optional) save each table as a separate file
     */
    public static function backup()
    {
        # The dumper speaks MySQL (SHOW CREATE, quoting, the works). PostgreSQL
        # ships its own excellent tool; pointing this one at it would produce a
        # file that restores nowhere.
        if (self::pg()) return Terminal::text('[color=red]`db backup` is MySQL-only - use pg_dump for a PostgreSQL connection.[/color]');

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
     * Split a dump into statements the way the server would read it.
     *
     * explode(';') cut every INSERT whose text held a semicolon, and the halves came
     * back as two syntax errors while the command still reported success. It also had
     * no idea about DELIMITER, which the dump wraps trigger and routine bodies in
     * precisely because those bodies are full of semicolons.
     *
     * @param string $sql
     * @return array
     */
    private static function splitStatements(string $sql): array
    {
        $statements = [];
        $current    = '';
        $quote      = null;
        $delimiter  = ';';
        $length     = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];

            if ($quote !== null) {
                $current .= $char;

                # A backslash escapes whatever follows, the closing quote included.
                if ($char === '\\' && $i + 1 < $length) {
                    $current .= $sql[++$i];
                    continue;
                }

                # A doubled quote inside a string is one literal quote, not the end of it.
                if ($char === $quote) {
                    if ($i + 1 < $length && $sql[$i + 1] === $quote) {
                        $current .= $sql[++$i];
                        continue;
                    }
                    $quote = null;
                }

                continue;
            }

            # DELIMITER is a client directive rather than SQL, so it is consumed here and
            # never handed to the server. \G anchors the match at the current offset.
            if (($i === 0 || $sql[$i - 1] === "\n") && preg_match('/\G[ \t]*DELIMITER[ \t]+(\S+)[^\n]*(\n|$)/i', $sql, $match, 0, $i)) {
                $delimiter = $match[1];
                $i += strlen($match[0]) - 1;
                continue;
            }

            # Comments are skipped whole, so a quote or a delimiter inside one is never
            # read: `-- Database: it's` opened a string that ran to the next apostrophe
            # in the data, and a `;` in a trailing comment cut the statement it followed.
            # `/*!` is kept - that is a versioned statement the server does execute.
            if ($char === '#' || ($char === '-' && preg_match('/\G--(?:[ \t]|\r?\n|$)/', $sql, $m, 0, $i))) {
                $end      = strpos($sql, "\n", $i);
                $i        = $end === false ? $length : $end;
                $current .= "\n";
                continue;
            }

            if ($char === '/' && substr($sql, $i, 2) === '/*' && substr($sql, $i, 3) !== '/*!') {
                $end = strpos($sql, '*/', $i);
                $i   = $end === false ? $length : $end + 1;
                continue;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                $quote    = $char;
                $current .= $char;
                continue;
            }

            if (substr($sql, $i, strlen($delimiter)) === $delimiter) {
                $statements[] = $current;
                $current      = '';
                $i           += strlen($delimiter) - 1;
                continue;
            }

            $current .= $char;
        }

        $statements[] = $current;

        return array_values(array_filter(array_map('trim', $statements), fn($statement) => $statement !== ''));
    }

    /**
     * Description: Restore a database backup (interactive)
     * Usage: php terminal db restore [--db=default]
     * @param --db={key}  (optional) db connection key, defaults to first
     */
    public static function restore()
    {
        # The dumps this reads are MySQL dumps written by `db backup`; replaying
        # one into PostgreSQL would fail statement by statement at best.
        if (self::pg()) return Terminal::text('[color=red]`db restore` is MySQL-only - use psql/pg_restore for a PostgreSQL connection.[/color]');

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
            $queries   = self::splitStatements($data);
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
