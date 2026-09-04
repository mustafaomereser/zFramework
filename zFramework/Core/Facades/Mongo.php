<?php

namespace zFramework\Core\Facades;

use zFramework\Core\Traits\Mongo\RelationShips;

/**
 * MongoDB, in the shape the DB facade has for SQL.
 *
 * Static side: the connections - one Manager per entry of
 * database/mongoconnections.php per process (the sibling of connections.php:
 * first entry is the default, empty file means Mongo is off), the database
 * name, raw commands.
 *
 * Instance side: a query against one collection. `new Mongo('conn')
 * ->collection('logs')->where(...)->get()` is the model-less form, exactly
 * as `new DB('conn')->table('x')` is; Abstracts\MongoModel extends this and
 * adds nothing but its properties, exactly as Model extends DB.
 *
 * Built on the mongodb extension directly (MongoDB\Driver\*), no library in
 * between: the driver already speaks wire protocol, pools per process and
 * hands documents back as arrays - a wrapper would only add a layer.
 *
 * Deliberately NOT a DB driver: DB's contract is building SQL, and none of
 * that has a Mongo meaning. The verbs whose meaning matches are here with
 * the same names and signatures - where/whereOr/whereIn/orderBy/limit,
 * get/first/find/count/paginate, insert/update/delete/updateOrInsert, the
 * relations - and the ones Mongo has that SQL does not (increment, push,
 * pull, distinct, aggregate) are here under their own names. Rows are
 * arrays; `_id` travels as its 24-hex string both ways.
 */
#[\AllowDynamicProperties]
class Mongo
{
    use RelationShips;

    // ─────────────────────────────────────────────
    // Connections (static)
    // ─────────────────────────────────────────────

    /**
     * One Manager per connection entry, per process. Boot state (State.php).
     */
    private static array $managers = [];

    /**
     * The entries, read once per process into $GLOBALS['databases'] next to
     * the SQL connections - where a test can register one at runtime.
     *
     * @return array
     */
    private static function connections(): array
    {
        return $GLOBALS['databases']['mongo'] ??= (array) (@include(BASE_PATH . '/database/mongoconnections.php') ?: []);
    }

    /**
     * @param string|null $connection
     * @return array|null
     */
    private static function entry(?string $connection): ?array
    {
        $connections = self::connections();
        if ($connection !== null) return $connections[$connection] ?? null;

        $first = array_key_first($connections);
        return $first === null ? null : $connections[$first];
    }

    /**
     * Whether anything here can work: the extension is loaded and the entry
     * exists. Cheap on the request path; never autoloads anything.
     *
     * @param string|null $connection
     * @return bool
     */
    public static function available(?string $connection = null): bool
    {
        return extension_loaded('mongodb') && self::entry($connection) !== null;
    }

    /**
     * @param string|null $connection
     * @return \MongoDB\Driver\Manager
     */
    public static function manager(?string $connection = null): \MongoDB\Driver\Manager
    {
        $key = $connection ?? (array_key_first(self::connections()) ?? '');
        if (isset(self::$managers[$key])) return self::$managers[$key];

        if (!extension_loaded('mongodb')) throw new \RuntimeException('Mongo: the mongodb extension is not loaded (php.ini: extension=mongodb).');

        $entry = self::entry($connection);
        if ($entry === null) throw new \RuntimeException($connection === null
            ? 'Mongo: no connection configured - database/mongoconnections.php.'
            : "Mongo: no connection `$connection` in database/mongoconnections.php.");

        return self::$managers[$key] = new \MongoDB\Driver\Manager($entry['uri'] ?? 'mongodb://127.0.0.1:27017');
    }

    /**
     * The database a connection's models write into unless they name their own.
     *
     * @param string|null $connection
     * @return string
     */
    public static function database(?string $connection = null): string
    {
        return (string) (self::entry($connection)['database'] ?? 'app');
    }

    /**
     * A raw database command, documents back as arrays - ping, createIndexes,
     * serverStatus, anything the wire accepts. A cursor-shaped answer comes
     * back already walked: the documents themselves, not a firstBatch wrapper.
     *
     * @param array       $command
     * @param string|null $database   Defaults to the connection's own.
     * @param string|null $connection
     * @return array
     */
    public static function command(array $command, ?string $database = null, ?string $connection = null): array
    {
        $cursor = self::manager($connection)->executeCommand($database ?? self::database($connection), new \MongoDB\Driver\Command($command));
        $cursor->setTypeMap(['root' => 'array', 'document' => 'array', 'array' => 'array']);

        return $cursor->toArray();
    }

    // ─────────────────────────────────────────────
    // A query (instance)
    // ─────────────────────────────────────────────

    /**
     * Connection entry name; null is the first one.
     */
    public $connection;

    /**
     * Collection name - set by collection(), or by a model's property.
     */
    public $collection;

    /**
     * Database name; empty means the connection entry's own.
     */
    public $database;

    /**
     * Fields left out of get()/first() when the query names none itself.
     */
    public $guard = [];

    /**
     * Observer class: oninsert(ed) / onupdate(d) / ondelete(d), as on SQL.
     */
    public $observe;

    # One query's state; reset after every run.
    private array $ands        = [];
    private array $ors         = [];
    private array $sort        = [];
    private ?array $projection = null;
    private int $skip          = 0;
    private ?int $take         = null;

    /**
     * @param string|null $connection Entry in database/mongoconnections.php; null = the first.
     */
    public function __construct(?string $connection = null)
    {
        if ($connection !== null) $this->connection = $connection;
    }

    /**
     * Point this query at a collection - the model-less way in.
     *
     * @param string $collection
     * @return static
     */
    public function collection(string $collection): static
    {
        $this->collection = $collection;
        return $this;
    }

    /**
     * The primary key's name - what `ex:` filters on in the validator.
     *
     * @return string
     */
    public function getPrimary(): string
    {
        return '_id';
    }

    // ── building ──

    /**
     * AND condition. Two arguments mean equals; the operator set mirrors the
     * SQL side: = != > >= < <= LIKE (case-insensitive substring) IN NOT IN.
     *
     * @param string $key
     * @param mixed  $a Operator, or the value when only two arguments came.
     * @param mixed  $b
     * @return static
     */
    public function where(string $key, mixed $a = null, mixed $b = null): static
    {
        $this->ands[] = $this->condition($key, func_num_args() === 2 ? '=' : $a, func_num_args() === 2 ? $a : $b);
        return $this;
    }

    /**
     * OR condition, exactly as on the SQL side: `where(a)->whereOr(b)->whereOr(c)`
     * reads "a OR b OR c".
     *
     * @param string $key
     * @param mixed  $a
     * @param mixed  $b
     * @return static
     */
    public function whereOr(string $key, mixed $a = null, mixed $b = null): static
    {
        $this->ors[] = $this->condition($key, func_num_args() === 2 ? '=' : $a, func_num_args() === 2 ? $a : $b);
        return $this;
    }

    /**
     * @param string $column
     * @param array  $in [] matches nothing, as on the SQL side.
     * @return static
     */
    public function whereIn(string $column, array $in = []): static
    {
        $this->ands[] = $in ? [$column => ['$in' => array_map(fn($v) => $this->id($column, $v), $in)]] : ['_id' => ['$exists' => false]];
        return $this;
    }

    /**
     * @param string $column
     * @param array  $in
     * @return static
     */
    public function whereNotIn(string $column, array $in = []): static
    {
        if ($in) $this->ands[] = [$column => ['$nin' => array_map(fn($v) => $this->id($column, $v), $in)]];
        return $this;
    }

    /**
     * @param string $column
     * @param mixed  $start
     * @param mixed  $stop
     * @return static
     */
    public function whereBetween(string $column, mixed $start, mixed $stop): static
    {
        $this->ands[] = [$column => ['$gte' => $start, '$lte' => $stop]];
        return $this;
    }

    /**
     * A raw match document, AND-ed in verbatim - the escape hatch for
     * $elemMatch, $exists, geo operators and the rest.
     *
     * @param array $match
     * @return static
     */
    public function filter(array $match): static
    {
        $this->ands[] = $match;
        return $this;
    }

    /**
     * @param array $data ['field' => 'ASC'|'DESC', ...]
     * @return static
     */
    public function orderBy(array $data): static
    {
        foreach ($data as $column => $direction) $this->sort[$column] = strtoupper((string) $direction) === 'DESC' ? -1 : 1;
        return $this;
    }

    /**
     * MySQL's signature, as everywhere in this framework: one argument is a
     * row count, two are offset + count.
     *
     * @param int      $startPoint
     * @param int|null $getCount
     * @return static
     */
    public function limit(int $startPoint = 0, ?int $getCount = null): static
    {
        if ($getCount === null) {
            $this->take = $startPoint;
        } else {
            $this->skip = $startPoint;
            $this->take = $getCount;
        }

        return $this;
    }

    /**
     * Fields to return - 'a, b' or ['a', 'b']. Overrides the guard.
     *
     * @param string|array $fields
     * @return static
     */
    public function select(string|array $fields): static
    {
        $fields = array_filter(is_array($fields) ? $fields : array_map('trim', explode(',', $fields)));

        # Nothing named is not "everything": an empty select() used to replace the
        # guard with an empty projection and hand the guarded fields back.
        if (!$fields) return $this;

        $this->projection = array_fill_keys($fields, 1);
        return $this;
    }

    // ── reading ──

    /**
     * @return array
     */
    public function get(): array
    {
        $options = ['sort' => (object) $this->sort];
        if ($this->skip) $options['skip'] = $this->skip;
        if ($this->take !== null) $options['limit'] = $this->take;

        $projection = $this->projection ?? (count($this->guard) ? array_fill_keys($this->guard, 0) : null);
        if ($projection) $options['projection'] = $projection;

        $cursor = self::manager($this->connection)->executeQuery($this->namespace(), new \MongoDB\Driver\Query($this->buildFilter(), $options));
        $cursor->setTypeMap(['root' => 'array', 'document' => 'array', 'array' => 'array']);

        $rows = [];
        foreach ($cursor as $row) $rows[] = $this->stringifyId($row);

        $this->reset();
        $this->loadRelations($rows);

        return $rows;
    }

    /**
     * @return array|null
     */
    public function first(): ?array
    {
        $this->take = 1;
        return $this->get()[0] ?? null;
    }

    /**
     * @param mixed $id
     * @return array|null
     */
    public function find(mixed $id): ?array
    {
        return $this->where('_id', $id)->first();
    }

    /**
     * @return bool
     */
    public function exists(): bool
    {
        return $this->count() > 0;
    }

    /**
     * @return int
     */
    public function count(): int
    {
        $command = ['count' => $this->collection];
        if ($filter = $this->buildFilter()) $command['query'] = (object) $filter;

        $answer = self::command($command, $this->databaseName(), $this->connection);
        $this->reset();

        return (int) ($answer[0]['n'] ?? 0);
    }

    /**
     * Distinct values of one field over the current filter.
     *
     * @param string $field
     * @return array
     */
    public function distinct(string $field): array
    {
        $command = ['distinct' => $this->collection, 'key' => $field];
        if ($filter = $this->buildFilter()) $command['query'] = (object) $filter;

        $answer = self::command($command, $this->databaseName(), $this->connection);
        $this->reset();

        return array_map(fn($v) => $v instanceof \MongoDB\BSON\ObjectId ? (string) $v : $v, (array) ($answer[0]['values'] ?? []));
    }

    /**
     * The same array the SQL paginate() returns - items, item_count, shown,
     * start, per_page, page_count, current_page and the links closure - so a
     * view built for one store renders the other unchanged.
     *
     * @param int    $per_page
     * @param string $page_id
     * @return array
     */
    public function paginate(int $per_page = 20, string $page_id = 'page'): array
    {
        # Two runs of the same filter: a count, then the page. The state is
        # taken before the count resets it.
        $ands = $this->ands; $ors = $this->ors; $sort = $this->sort; $projection = $this->projection; $eager = $this->eagerLoad;

        $row_count = $this->count();

        $this->ands = $ands; $this->ors = $ors; $this->sort = $sort; $this->projection = $projection; $this->eagerLoad = $eager;

        $uniqueID     = uniqid();
        $current_page = max(1, (int) (is_scalar($page = request($page_id)) ? $page : 1));
        $page_count   = (int) ceil($row_count / $per_page);
        if ($current_page > $page_count) $current_page = max(1, $page_count);

        $start_count = $per_page * ($current_page - 1);
        if (!$row_count) $start_count = -1;

        @parse_str((string) ($_SERVER['QUERY_STRING'] ?? ''), $queryString);
        $queryString[$page_id] = "change_page_$uniqueID";
        $url = '?' . http_build_query($queryString);

        $items = $row_count ? $this->limit(max(0, $start_count), $per_page)->get() : [];
        $this->reset();

        return [
            'items'        => $items,
            'item_count'   => $row_count,
            'shown'        => ($start_count + 1) . ' / ' . (($per_page * $current_page) >= $row_count ? $row_count : ($per_page * $current_page)),
            'start'        => $start_count + 1,
            'per_page'     => $per_page,
            'page_count'   => $page_count,
            'current_page' => $current_page,
            'links'        => function ($view = null) use ($page_count, $current_page, $url, $uniqueID) {
                if (!$view) $view = Config::framework('pagination.default-view') ?? config('app.pagination.default-view');

                $pages = [];
                for ($x = 1; $x <= $page_count; $x++) $pages[$x] = [
                    'type'    => 'page',
                    'page'    => $x,
                    'current' => $x == $current_page,
                    'url'     => str_replace("change_page_$uniqueID", $x, $url),
                ];

                return view($view, compact('pages', 'page_count', 'current_page', 'url', 'uniqueID'));
            },
        ];
    }

    /**
     * An aggregation pipeline, documents back as arrays - groupings, lookups,
     * facets live here rather than as half-true SQL verbs.
     *
     * @param array $pipeline
     * @return array
     */
    public function aggregate(array $pipeline): array
    {
        # The where() chain travels as the first $match stage - it used to be
        # reset() away silently, so a filtered-looking aggregate was not.
        if ($filter = $this->buildFilter()) array_unshift($pipeline, ['$match' => $filter]);

        $command = ['aggregate' => $this->collection, 'pipeline' => $pipeline, 'cursor' => (object) []];
        $rows    = array_map(fn($row) => $this->stringifyId((array) $row), self::command($command, $this->databaseName(), $this->connection));
        $this->reset();

        return $rows;
    }

    // ── writing ──

    /**
     * Insert one document. `_id` is generated when the caller did not bring
     * one, and the finished row comes straight back - nothing is re-read.
     *
     * @param array $sets
     * @param bool  $just_insert True: return 1 instead of the row.
     * @return array|int
     */
    public function insert(array $sets = [], bool $just_insert = false): array|int
    {
        if ($new_sets = $this->trigger('insert', $sets)) $sets = $new_sets;

        $sets['_id'] = isset($sets['_id']) ? $this->id('_id', $sets['_id']) : new \MongoDB\BSON\ObjectId();

        $bulk = new \MongoDB\Driver\BulkWrite();
        $bulk->insert($sets);
        self::manager($this->connection)->executeBulkWrite($this->namespace(), $bulk);

        $row = $this->stringifyId($sets);
        $this->reset();

        if ($just_insert) return 1;

        $this->trigger('inserted', $row);
        return $row;
    }

    /**
     * $set the given fields on every matching document.
     *
     * @param array $sets
     * @return int Documents modified.
     */
    public function update(array $sets = []): int
    {
        if ($new_sets = $this->trigger('update', $sets)) $sets = $new_sets;

        $updated = $this->write(['$set' => $sets]);
        if ($updated) $this->trigger('updated');

        return $updated;
    }

    /**
     * Update the matching document, or insert one carrying the filter's
     * equality fields plus $sets when nothing matched - a real upsert, one
     * round-trip, unlike the SQL side's select-then-write.
     *
     * @param array $sets
     * @return int Documents modified or inserted.
     */
    public function updateOrInsert(array $sets = []): int
    {
        if ($new_sets = $this->trigger('update', $sets)) $sets = $new_sets;

        $bulk = new \MongoDB\Driver\BulkWrite();
        $bulk->update($this->buildFilter(), ['$set' => $sets], ['multi' => false, 'upsert' => true]);
        $result = self::manager($this->connection)->executeBulkWrite($this->namespace(), $bulk);

        $this->reset();
        $touched = (int) $result->getModifiedCount() + (int) $result->getUpsertedCount();
        if ($touched) $this->trigger('updated');

        return $touched;
    }

    /**
     * Add to numeric fields atomically - a counter that survives concurrent
     * requests, which read-modify-write cannot promise.
     *
     * @param string $field
     * @param int|float $by
     * @return int
     */
    public function increment(string $field, int|float $by = 1): int
    {
        return $this->write(['$inc' => [$field => $by]]);
    }

    /**
     * @param string    $field
     * @param int|float $by
     * @return int
     */
    public function decrement(string $field, int|float $by = 1): int
    {
        return $this->write(['$inc' => [$field => -$by]]);
    }

    /**
     * Append to an array field ($push); the field is created if missing.
     *
     * @param string $field
     * @param mixed  $value One value, or several as ['$each' => [...]] pass through.
     * @return int
     */
    public function push(string $field, mixed $value): int
    {
        return $this->write(['$push' => [$field => $value]]);
    }

    /**
     * Remove every occurrence of a value from an array field ($pull).
     *
     * @param string $field
     * @param mixed  $value
     * @return int
     */
    public function pull(string $field, mixed $value): int
    {
        return $this->write(['$pull' => [$field => $value]]);
    }

    /**
     * Delete every matching document. Real deletion - keep an own flag
     * field if you need a trash can.
     *
     * @return int
     */
    public function delete(): int
    {
        $this->trigger('delete');

        $bulk = new \MongoDB\Driver\BulkWrite();
        $bulk->delete($this->buildFilter(), ['limit' => 0]);
        $result = self::manager($this->connection)->executeBulkWrite($this->namespace(), $bulk);

        $this->reset();
        $deleted = (int) $result->getDeletedCount();
        if ($deleted) $this->trigger('deleted');

        return $deleted;
    }

    /**
     * Indexes `php terminal mongo indexes` creates; models override.
     *
     * @return array
     */
    public function indexes(): array
    {
        return [];
    }

    // ── internals ──

    /**
     * @return string db.collection
     */
    public function namespace(): string
    {
        if (!$this->collection) throw new \Exception(static::class . ': no collection - call collection() or set public $collection.');
        return $this->databaseName() . '.' . $this->collection;
    }

    /**
     * @return string
     */
    private function databaseName(): string
    {
        return $this->database ?: self::database($this->connection);
    }

    /**
     * A multi-document update operator against the current filter.
     *
     * @param array $operation
     * @return int
     */
    private function write(array $operation): int
    {
        $bulk = new \MongoDB\Driver\BulkWrite();
        $bulk->update($this->buildFilter(), $operation, ['multi' => true]);
        $result = self::manager($this->connection)->executeBulkWrite($this->namespace(), $bulk);

        $this->reset();
        return (int) $result->getModifiedCount();
    }

    /**
     * @param string $key
     * @param string $operator
     * @param mixed  $value
     * @return array
     */
    private function condition(string $key, string $operator, mixed $value): array
    {
        $value = $this->id($key, $value);

        return match (strtoupper($operator)) {
            '='          => [$key => $value],
            '!=', '<>'   => [$key => ['$ne' => $value]],
            '>'          => [$key => ['$gt' => $value]],
            '>='         => [$key => ['$gte' => $value]],
            '<'          => [$key => ['$lt' => $value]],
            '<='         => [$key => ['$lte' => $value]],
            # LIKE without the %s: a case-insensitive substring match, which is
            # what nearly every LIKE in application code means.
            'LIKE'       => [$key => ['$regex' => preg_quote(trim((string) $value, '%'), '/'), '$options' => 'i']],
            'IN'         => [$key => ['$in' => (array) $value]],
            'NOT IN'     => [$key => ['$nin' => (array) $value]],
            default      => throw new \Exception("Mongo: unknown operator `$operator`."),
        };
    }

    /**
     * The finished match document. No whereOr(): the ANDs, plain. With any:
     * the AND block becomes one alternative among the ors, as the SQL builder
     * reads the same chain.
     *
     * @return array
     */
    private function buildFilter(): array
    {
        $and = count($this->ands) > 1 ? ['$and' => $this->ands] : ($this->ands[0] ?? null);

        if (!$this->ors) return $and ?? [];

        $alternatives = $this->ors;
        if ($and !== null) array_unshift($alternatives, $and);

        return count($alternatives) === 1 ? $alternatives[0] : ['$or' => $alternatives];
    }

    /**
     * `_id` both ways: a 24-hex string becomes an ObjectId on the way in.
     *
     * @param string $key
     * @param mixed  $value
     * @return mixed
     */
    private function id(string $key, mixed $value): mixed
    {
        if ($key === '_id' && is_string($value) && preg_match('/^[0-9a-f]{24}$/i', $value)) return new \MongoDB\BSON\ObjectId($value);
        return $value;
    }

    /**
     * @param array $row
     * @return array
     */
    private function stringifyId(array $row): array
    {
        if (isset($row['_id']) && $row['_id'] instanceof \MongoDB\BSON\ObjectId) $row['_id'] = (string) $row['_id'];
        return $row;
    }

    /**
     * @return void
     */
    private function reset(): void
    {
        $this->ands       = [];
        $this->ors        = [];
        $this->sort       = [];
        $this->projection = null;
        $this->skip       = 0;
        $this->take       = null;
    }

    /**
     * Same observer contract as the SQL models.
     *
     * @param string $name
     * @param mixed  $args
     * @return mixed
     */
    private function trigger(string $name, mixed $args = []): mixed
    {
        if (!$this->observe) return false;
        return call_user_func_array([new ($this->observe), 'router'], [$name, $args]);
    }
}
