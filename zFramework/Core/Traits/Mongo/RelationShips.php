<?php

namespace zFramework\Core\Traits\Mongo;

/**
 * Thrown by a relation method while it is being probed for eager loading -
 * the same signal Traits\DB uses: the probe needs the model, field and value
 * a relation would query without letting it query.
 */
class EagerProbe extends \Exception
{
}

/**
 * Relations between collections - and across stores.
 *
 * Same verbs and same signatures as the SQL trait, so a MongoModel reads
 * like a Model: `$this->hasMany(Comment::class, $row['_id'], 'post_id')`.
 * The related model may be another MongoModel OR an SQL Model: everything
 * here goes through where()/whereIn()/get()/first(), which both answer, so a
 * post in Mongo can own comments in MySQL and a MySQL user can own logs in
 * Mongo without either side knowing.
 *
 * Eager loading is the SQL trait's design: with('comments') records what each
 * row would ask for, then fires one $in (or IN) query per relation instead of
 * one per row.
 */
trait RelationShips
{
    /**
     * Relations queued by with(), resolved after get().
     */
    public array $eagerLoad = [];

    /**
     * Where a relation being probed writes what it would query.
     */
    private ?array $eagerProbe = null;

    /**
     * @param string ...$relations
     * @return static
     */
    public function with(string ...$relations): static
    {
        foreach ($relations as $relation) if (!in_array($relation, $this->eagerLoad, true)) $this->eagerLoad[] = $relation;
        return $this;
    }

    /**
     * @param string $model
     * @param string $column
     * @param mixed  $value
     * @param bool   $many
     * @return void
     */
    private function eagerCapture(string $model, string $column, mixed $value, bool $many): void
    {
        $this->eagerProbe = compact('model', 'column', 'value', 'many');
        throw new EagerProbe();
    }

    /**
     * One-to-many: every related document whose $column holds $value.
     *
     * @param string          $model  Related class - MongoModel or SQL Model.
     * @param string|int|null $value  This document's key.
     * @param string|null     $column Foreign field on the related side; defaults to `<collection>_id`.
     * @return array
     */
    public function hasMany(string $model, string|int|null $value, ?string $column = null): array
    {
        $column ??= $this->collection . '_id';
        if ($this->eagerProbe !== null) $this->eagerCapture($model, $column, $value, true);
        if ($value === null) return [];
        return (new $model)->where($column, $value)->get();
    }

    /**
     * One-to-one.
     *
     * @param string          $model
     * @param string|int|null $value
     * @param string|null     $column
     * @return array|null
     */
    public function hasOne(string $model, string|int|null $value, ?string $column = null): ?array
    {
        $column ??= $this->collection . '_id';
        if ($this->eagerProbe !== null) $this->eagerCapture($model, $column, $value, false);
        if ($value === null) return null;
        return (new $model)->where($column, $value)->first() ?: null;
    }

    /**
     * Inverse: the parent this document points at.
     *
     * @param string          $model
     * @param string|int|null $value  The foreign key stored on this document.
     * @param string|null     $column Parent's key; defaults to the parent's primary.
     * @return array|null
     */
    public function belongsTo(string $model, string|int|null $value, ?string $column = null): ?array
    {
        $instance = new $model;
        $column ??= $instance->getPrimary();
        if ($this->eagerProbe !== null) $this->eagerCapture($model, $column, $value, false);
        if ($value === null) return null;
        return $instance->where($column, $value)->first() ?: null;
    }

    /**
     * Resolve every with() relation for a result set - one query per relation.
     *
     * @param array $results Rows as get() built them; relations are added in place.
     * @return void
     */
    private function loadRelations(array &$results): void
    {
        if (!$this->eagerLoad || !$results) return;

        foreach ($this->eagerLoad as $relation) {
            if (!method_exists($this, $relation)) throw new \Exception(static::class . "::$relation() is not a relation.");

            # Pass 1: probe each row for what the relation would query.
            $intents = [];
            foreach ($results as $index => $row) {
                $this->eagerProbe = [];
                try {
                    $this->$relation($row);
                } catch (EagerProbe) {
                    $intents[$index] = $this->eagerProbe;
                } finally {
                    $this->eagerProbe = null;
                }
            }

            # Pass 2: one query per model+column pair, rows handed back by key.
            $groups = [];
            foreach ($intents as $index => $intent) {
                $key = $intent['model'] . '|' . $intent['column'];
                $groups[$key]['model']          = $intent['model'];
                $groups[$key]['column']         = $intent['column'];
                $groups[$key]['many']           = $intent['many'];
                $groups[$key]['values'][$index] = $intent['value'];
            }

            foreach ($groups as $group) {
                $wanted  = array_values(array_unique(array_filter($group['values'], fn($v) => $v !== null && $v !== '')));
                $related = [];

                if ($wanted) foreach ((new $group['model'])->whereIn($group['column'], $wanted)->get() as $row)
                    $related[(string) $row[$group['column']]][] = $row;

                foreach ($group['values'] as $index => $value) {
                    $matches = $related[(string) $value] ?? [];
                    $results[$index][$relation] = $group['many'] ? $matches : ($matches[0] ?? null);
                }
            }
        }

        $this->eagerLoad = [];
    }
}
