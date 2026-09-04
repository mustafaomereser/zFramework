<?php

namespace zFramework\Core\Abstracts;

use zFramework\Core\Facades\Mongo;

/**
 * A collection, spoken in this framework's own verbs.
 *
 * Lives in App/Models beside the SQL models; the only difference is the
 * extends. Rows are arrays here too, `_id` included - an ObjectId comes back
 * as its 24-hex string and a 24-hex string you pass in is sent as an
 * ObjectId, so the id in a url round-trips without anyone touching the BSON
 * class.
 *
 *   class Log extends MongoModel
 *   {
 *       public $collection = 'logs';
 *   }
 *
 *   (new Log)->where('level', 'error')->orderBy(['at' => 'DESC'])->limit(20)->get();
 *   (new Log)->insert(['level' => 'error', 'text' => '...']);   // the row, _id filled in
 *
 * What deliberately is not here: joins, whereRaw, migrations, softDelete -
 * SQL ideas with no honest Mongo meaning. The escape hatches are filter()
 * for a raw match and aggregate() for a pipeline.
 *
 * Validator's unique/exists work against these models untouched: they call
 * where()/count()/getPrimary(), and all three answer.
 */
#[\AllowDynamicProperties]
abstract class MongoModel
{
    /**
     * Collection name. Required.
     */
    public $collection;

    /**
     * Connection name from database/mongoconnections.php; empty means the
     * first entry, exactly as $db works on the SQL models.
     */
    public $connection;

    /**
     * Database name; empty means the connection entry's own.
     */
    public $database;

    /**
     * Observer class - same hooks as the SQL models: oninsert(ed),
     * onupdate(d), ondelete(d). oninsert/onupdate receive the sets and may
     * return replacements.
     */
    public $observe;

    /**
     * Fields left out of get()/first() when the query names none itself -
     * the guard the SQL models have, spelled as a projection.
     */
    public $guard = [];

    # One query's state; reset after every run.
    private array $ands       = [];
    private array $ors        = [];
    private array $sort       = [];
    private ?array $projection = null;
    private int $skip         = 0;
    private ?int $take        = null;

    public function __construct()
    {
        if (!$this->collection) throw new \Exception(static::class . ': set public $collection.');
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

    // ─────────────────────────────────────────────
    // Building
    // ─────────────────────────────────────────────

    /**
     * AND condition. Two arguments mean equals; the operator set mirrors the
     * SQL side: = != > >= < <= LIKE (case-insensitive substring) IN NOT IN.
     *
     * @param string $key
     * @param mixed  $a  Operator, or the value when only two arguments came.
     * @param mixed  $b
     * @return static
     */
    public function where(string $key, mixed $a = null, mixed $b = null): static
    {
        $this->ands[] = $this->condition($key, func_num_args() === 2 ? '=' : $a, func_num_args() === 2 ? $a : $b);
        return $this;
    }

    /**
     * OR condition, exactly as on the SQL side: the chain
     * `where(a)->whereOr(b)->whereOr(c)` reads "a OR b OR c" - each whereOr()
     * offers an alternative to everything the where() calls built.
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
     * @param array  $in
     * @return static
     */
    public function whereIn(string $column, array $in = []): static
    {
        # An empty list matches nothing, as it does on the SQL side.
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
     * A raw match document, verbatim - the escape hatch when the verbs above
     * do not reach ($elemMatch, $exists, geo operators ...).
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
     * @param array $data ['column' => 'ASC'|'DESC', ...]
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
     * Fields to return: 'a, b' or ['a', 'b']. Overrides the guard.
     *
     * @param string|array $fields
     * @return static
     */
    public function select(string|array $fields): static
    {
        $fields = is_array($fields) ? $fields : array_map('trim', explode(',', $fields));
        $this->projection = array_fill_keys(array_filter($fields), 1);
        return $this;
    }

    // ─────────────────────────────────────────────
    // Running
    // ─────────────────────────────────────────────

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

        $cursor = Mongo::manager($this->connection)->executeQuery($this->namespace(), new \MongoDB\Driver\Query($this->buildFilter(), $options));
        $cursor->setTypeMap(['root' => 'array', 'document' => 'array', 'array' => 'array']);

        $rows = [];
        foreach ($cursor as $row) $rows[] = $this->stringifyId($row);

        $this->reset();
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
     * By primary key.
     *
     * @param mixed $id
     * @return array|null
     */
    public function find(mixed $id): ?array
    {
        return $this->where('_id', $id)->first();
    }

    /**
     * @return int
     */
    public function count(): int
    {
        $command = ['count' => $this->collection];
        if ($filter = $this->buildFilter()) $command['query'] = (object) $filter;

        $answer = Mongo::command($command, $this->databaseName(), $this->connection);
        $this->reset();

        return (int) ($answer[0]['n'] ?? 0);
    }

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
        Mongo::manager($this->connection)->executeBulkWrite($this->namespace(), $bulk);

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

        $bulk = new \MongoDB\Driver\BulkWrite();
        $bulk->update($this->buildFilter(), ['$set' => $sets], ['multi' => true]);
        $result = Mongo::manager($this->connection)->executeBulkWrite($this->namespace(), $bulk);

        $this->reset();
        $updated = (int) $result->getModifiedCount();
        if ($updated) $this->trigger('updated');

        return $updated;
    }

    /**
     * Delete every matching document. Real deletion - there is no softDelete
     * here; keep an own flag field if you need a trash can.
     *
     * @return int
     */
    public function delete(): int
    {
        $this->trigger('delete');

        $bulk = new \MongoDB\Driver\BulkWrite();
        $bulk->delete($this->buildFilter(), ['limit' => 0]);
        $result = Mongo::manager($this->connection)->executeBulkWrite($this->namespace(), $bulk);

        $this->reset();
        $deleted = (int) $result->getDeletedCount();
        if ($deleted) $this->trigger('deleted');

        return $deleted;
    }

    /**
     * An aggregation pipeline, documents back as arrays. The power tool -
     * groupings, lookups, facets live here rather than as half-true SQL verbs.
     *
     * @param array $pipeline
     * @return array
     */
    public function aggregate(array $pipeline): array
    {
        $command = ['aggregate' => $this->collection, 'pipeline' => $pipeline, 'cursor' => (object) []];

        # executeCommand() walks a cursor-shaped answer itself: what comes back
        # IS the documents, not a wrapper with firstBatch inside.
        return array_map(fn($row) => $this->stringifyId((array) $row), Mongo::command($command, $this->databaseName(), $this->connection));
    }

    /**
     * Indexes `php terminal mongo indexes` creates. Override:
     *
     *   public function indexes(): array
     *   {
     *       return [
     *           ['key' => ['level' => 1, 'at' => -1]],
     *           ['key' => ['email' => 1], 'unique' => true],
     *       ];
     *   }
     *
     * @return array
     */
    public function indexes(): array
    {
        return [];
    }

    // ─────────────────────────────────────────────
    // Internals
    // ─────────────────────────────────────────────

    /**
     * @return string db.collection
     */
    public function namespace(): string
    {
        return $this->databaseName() . '.' . $this->collection;
    }

    /**
     * @return string
     */
    private function databaseName(): string
    {
        return $this->database ?: Mongo::database($this->connection);
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
            '='      => [$key => $value],
            '!=', '<>' => [$key => ['$ne' => $value]],
            '>'      => [$key => ['$gt' => $value]],
            '>='     => [$key => ['$gte' => $value]],
            '<'      => [$key => ['$lt' => $value]],
            '<='     => [$key => ['$lte' => $value]],
            # LIKE without the %s: a case-insensitive substring match, which is
            # what nearly every LIKE in application code means.
            'LIKE'   => [$key => ['$regex' => preg_quote(trim((string) $value, '%'), '/'), '$options' => 'i']],
            'IN'     => [$key => ['$in' => (array) $value]],
            'NOT IN' => [$key => ['$nin' => (array) $value]],
            default  => throw new \Exception("MongoModel: unknown operator `$operator`."),
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
     * `_id` both ways: a 24-hex string becomes an ObjectId on the way in, so
     * the id printed into a url matches the stored key again.
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
        if (!isset($this->observe)) return false;
        return call_user_func_array([new ($this->observe), 'router'], [$name, $args]);
    }
}
