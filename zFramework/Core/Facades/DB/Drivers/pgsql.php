<?php

namespace zFramework\Core\Facades\DB\Drivers;

/**
 * PostgreSQL. Picked automatically: DB::connection() reads the PDO driver name
 * and loads Drivers/<name>.php, so a `pgsql:` DSN in connections.php is all it
 * takes. Extends mysql the way sqlsrv does - the SQL the base builder writes
 * is standard enough that only the schema catalog, LIMIT and the insert tail
 * differ.
 *
 * What the application feels:
 *   - limit($start, $count) keeps its MySQL signature; the OFFSET spelling is
 *     written here.
 *   - insert() costs ONE round-trip: build() appends RETURNING *, and
 *     DB::insert() reads the row straight off the statement instead of asking
 *     lastInsertId() and selecting it back.
 *   - Identifiers are used as written, unquoted - PostgreSQL folds them to
 *     lower case, so name tables and columns in lower case (the convention
 *     everywhere in this framework already).
 */
class pgsql extends mysql
{
    protected $parent;

    public function __construct($parent)
    {
        $this->parent = $parent;

        if (!isset($GLOBALS['databases']['connected'][$this->parent->db]['name']))
            $GLOBALS['databases']['connected'][$this->parent->db]['name'] = $GLOBALS['databases']['connections'][$this->parent->db]->query('SELECT current_database()')->fetchColumn();
    }

    /**
     * Table scheme blueprint, in the exact shape mysql::tables() returns -
     * everything above the driver (the scheme cache, columns(), getPrimary())
     * reads that shape.
     *
     * PostgreSQL scopes tables by SCHEMA, not by database name, and its
     * information_schema answers in lower case; the aliases are quoted so the
     * keys come back exactly as the rest of the framework spells them.
     *
     * @return array
     */
    public function tables(): array
    {
        $tables = $this->parent->prepare(
            'SELECT table_name AS "TABLE_NAME" FROM information_schema.tables WHERE table_schema = current_schema() AND table_type = \'BASE TABLE\' ORDER BY table_name'
        )->fetchAll(\PDO::FETCH_COLUMN);

        # All columns in one round-trip, as the mysql driver does - the count of
        # information_schema queries is what costs when the scheme cache is cold.
        $rows = $this->parent->prepare(
            'SELECT table_name AS "TABLE_NAME", column_name AS "COLUMN_NAME", character_maximum_length AS "CHARACTER_MAXIMUM_LENGTH", data_type AS "COLUMN_TYPE" FROM information_schema.columns WHERE table_schema = current_schema() ORDER BY table_name, ordinal_position'
        )->fetchAll(\PDO::FETCH_ASSOC);

        # Primary keys, one query for the whole schema. COLUMN_KEY does not
        # exist here; the constraint catalog says which column is PRI.
        $primaries = $this->parent->prepare(
            'SELECT kcu.table_name AS "TABLE_NAME", kcu.column_name AS "COLUMN_NAME"
             FROM information_schema.table_constraints tc
             JOIN information_schema.key_column_usage kcu ON kcu.constraint_name = tc.constraint_name AND kcu.table_schema = tc.table_schema
             WHERE tc.constraint_type = \'PRIMARY KEY\' AND tc.table_schema = current_schema()'
        )->fetchAll(\PDO::FETCH_KEY_PAIR);

        $grouped = [];
        foreach ($rows as $row) {
            $table = $row['TABLE_NAME'];
            unset($row['TABLE_NAME']);
            $row['COLUMN_KEY'] = ($primaries[$table] ?? null) === $row['COLUMN_NAME'] ? 'PRI' : '';
            $grouped[$table][] = $row;
        }

        $data = ['TABLE_COLUMNS' => []];
        $engines = [];

        foreach ($tables as $name) {
            $engines[$name] = null; # storage engines are a MySQL concept
            $columns        = $grouped[$name] ?? [];
            $data['TABLE_COLUMNS'][$name] = [
                'primary' => $primaries[$name] ?? null,
                'columns' => $columns,
            ];
        }

        $data['TABLES']        = $tables;
        $data['TABLE_ENGINES'] = $engines;

        return $data;
    }

    /**
     * limit($start, $count) in PostgreSQL spelling.
     *
     * The base class writes MySQL's `LIMIT start, count`, which is a syntax
     * error here. Same signature, same meaning: one argument is a row count,
     * two are offset + count.
     *
     * @return null|string
     */
    protected function getLimit(): null|string
    {
        $limit = @$this->parent->buildQuery['limit'];
        if (!$limit) return null;

        return $limit[1] ? ' LIMIT ' . $limit[1] . ' OFFSET ' . $limit[0] . ' ' : ' LIMIT ' . $limit[0] . ' ';
    }

    /**
     * LIKE is case-sensitive in PostgreSQL and (with the usual collations)
     * not in MySQL, so the same where() found different rows. ILIKE is the
     * PostgreSQL spelling of what the application meant.
     *
     * @param string $operator
     * @return string
     */
    protected function operator(string $operator): string
    {
        return match (strtoupper(trim($operator))) {
            'LIKE'     => 'ILIKE',
            'NOT LIKE' => 'NOT ILIKE',
            default    => $operator,
        };
    }

    /**
     * INSERT carries its answer back: RETURNING * hands DB::insert() the full
     * row on the same round-trip, where MySQL needs lastInsertId() plus a
     * SELECT - and it works for any key, serial or not.
     *
     * @param string $type
     * @return string
     */
    public function build(string $type): string
    {
        $sql = parent::build($type);
        if ($type === 'insert') $sql .= ' RETURNING *';
        return $sql;
    }
}
