<?php

namespace zFramework\Core\Traits\DB;

trait RelationShips
{
    /**
     * Base relation finder.
     *
     * @param string      $model  Related model class name.
     * @param string      $value  Value to match against the foreign key.
     * @param string|null $column Foreign key column. Defaults to {this_table}_id.
     * @return self
     */
    public function findRelation(string $model, string $value, ?string $column = null): self
    {
        if (!$column) $column = $this->table . "_id";
        return (new $model)->where($column, $value);
    }

    // ─────────────────────────────────────────────
    // BASIC RELATIONS
    // ─────────────────────────────────────────────

    /**
     * One-to-many relationship. Returns all related records.
     *
     * @param string      $model  Related model class name.
     * @param string      $value  Parent's primary key value.
     * @param string|null $column Foreign key column on the related table.
     * @return array
     *
     *   public function posts(array $values) {
     *       return $this->hasMany(Post::class, $values['id'], 'user_id');
     *   }
     */
    public function hasMany(string $model, string $value, ?string $column = null): array
    {
        return $this->findRelation($model, $value, $column)->get();
    }

    /**
     * One-to-one relationship. Returns a single related record.
     *
     * @param string      $model  Related model class name.
     * @param string      $value  Parent's primary key value.
     * @param string|null $column Foreign key column on the related table.
     * @return array|null
     *
     *   public function profile(array $values) {
     *       return $this->hasOne(Profile::class, $values['id'], 'user_id');
     *   }
     */
    public function hasOne(string $model, string $value, ?string $column = null): ?array
    {
        return $this->findRelation($model, $value, $column)->first();
    }

    /**
     * Inverse of hasOne/hasMany. Returns the parent record.
     *
     * @param string      $model  Parent model class name.
     * @param string      $value  Foreign key value on the current model.
     * @param string|null $column Primary key column on the parent. Defaults to parent's getPrimary().
     * @return array|null
     *
     *   public function user(array $values) {
     *       return $this->belongsTo(User::class, $values['user_id']);
     *   }
     */
    public function belongsTo(string $model, string $value, ?string $column = null): ?array
    {
        $instance = new $model;
        if (!$column) $column = $instance->getPrimary();
        return $instance->where($column, $value)->first();
    }

    // ─────────────────────────────────────────────
    // MANY TO MANY (Pivot table)
    // ─────────────────────────────────────────────

    /**
     * Many-to-many relationship through a pivot table.
     *
     * @param string      $model           Related model class name.
     * @param string      $pivotTable      Pivot table name.
     * @param string      $value           Current model's key value.
     * @param string      $foreignKey      Pivot column referencing this model.
     * @param string      $relatedKey      Pivot column referencing the related model.
     * @param string|null $localKey        This model's primary key. Defaults to getPrimary().
     * @param string|null $relatedLocalKey Related model's primary key. Defaults to related getPrimary().
     * @return array
     *
     *   public function roles(array $values) {
     *       return $this->belongsToMany(Role::class, 'user_roles', $values['id'], 'user_id', 'role_id');
     *   }
     */
    public function belongsToMany(
        string $model,
        string $pivotTable,
        string $value,
        string $foreignKey,
        string $relatedKey,
        ?string $localKey = null,
        ?string $relatedLocalKey = null
    ): array {
        $instance        = new $model;
        $relatedTable    = $instance->table;
        $localKey        = $localKey ?? $this->getPrimary();
        $relatedLocalKey = $relatedLocalKey ?? $instance->getPrimary();

        return $instance
            ->join(null, $model, "{$pivotTable}.{$relatedKey} = {$relatedTable}.{$relatedLocalKey}")
            ->whereRaw("{$pivotTable}.{$foreignKey} = :value", compact('value'))
            ->get();
    }

    /**
     * Many-to-many relationship with additional pivot columns.
     *
     * @param string      $model           Related model class name.
     * @param string      $pivotTable      Pivot table name.
     * @param string      $value           Current model's key value.
     * @param string      $foreignKey      Pivot column referencing this model.
     * @param string      $relatedKey      Pivot column referencing the related model.
     * @param array       $pivotColumns    Extra pivot columns to select (returned as pivot_{column}).
     * @param string|null $localKey        This model's primary key.
     * @param string|null $relatedLocalKey Related model's primary key.
     * @return array
     *
     *   public function roles(array $values) {
     *       return $this->belongsToManyWithPivot(
     *           Role::class, 'user_roles', $values['id'],
     *           'user_id', 'role_id', ['assigned_at', 'expires_at']
     *       );
     *   }
     */
    public function belongsToManyWithPivot(
        string $model,
        string $pivotTable,
        string $value,
        string $foreignKey,
        string $relatedKey,
        array $pivotColumns = [],
        ?string $localKey = null,
        ?string $relatedLocalKey = null
    ): array {
        $instance        = new $model;
        $relatedTable    = $instance->table;
        $localKey        = $localKey ?? $this->getPrimary();
        $relatedLocalKey = $relatedLocalKey ?? $instance->getPrimary();

        $pivotSelect = '';
        if (!empty($pivotColumns)) $pivotSelect = ', ' . implode(', ', array_map(fn($col) => "{$pivotTable}.{$col} as pivot_{$col}", $pivotColumns));

        return $instance
            ->select("{$relatedTable}.*{$pivotSelect}")
            ->join(null, $model, "{$pivotTable}.{$relatedKey} = {$relatedTable}.{$relatedLocalKey}")
            ->whereRaw("{$pivotTable}.{$foreignKey} = :value", compact('value'))
            ->get();
    }

    // ─────────────────────────────────────────────
    // HAS THROUGH (Indirect relations)
    // ─────────────────────────────────────────────

    /**
     * Has-many through an intermediate model.
     * Example: Country -> User -> Post
     *
     * @param string      $model          Final target model class name.
     * @param string      $throughModel   Intermediate model class name.
     * @param string      $value          Current model's key value.
     * @param string      $firstKey       Intermediate table column referencing this model.
     * @param string      $secondKey      Final table column referencing the intermediate model.
     * @param string|null $localKey       This model's primary key. Defaults to getPrimary().
     * @param string|null $secondLocalKey Intermediate model's primary key. Defaults to through getPrimary().
     * @return array
     *
     *   public function posts(array $values) {
     *       return $this->hasManyThrough(
     *           Post::class, User::class, $values['id'],
     *           'country_id', 'user_id'
     *       );
     *   }
     */
    public function hasManyThrough(
        string $model,
        string $throughModel,
        string $value,
        string $firstKey,
        string $secondKey,
        ?string $localKey = null,
        ?string $secondLocalKey = null
    ): array {
        $instance       = new $model;
        $through        = new $throughModel;
        $finalTable     = $instance->table;
        $throughTable   = $through->table;
        $localKey       = $localKey ?? $this->getPrimary();
        $secondLocalKey = $secondLocalKey ?? $through->getPrimary();

        return $instance
            ->join(null, $model, "{$throughTable}.{$secondLocalKey} = {$finalTable}.{$secondKey}")
            ->whereRaw("{$throughTable}.{$firstKey} = :value", compact('value'))
            ->get();
    }

    /**
     * Has-one through an intermediate model. Returns a single record.
     *
     * @param string      $model          Final target model class name.
     * @param string      $throughModel   Intermediate model class name.
     * @param string      $value          Current model's key value.
     * @param string      $firstKey       Intermediate table column referencing this model.
     * @param string      $secondKey      Final table column referencing the intermediate model.
     * @param string|null $localKey       This model's primary key.
     * @param string|null $secondLocalKey Intermediate model's primary key.
     * @return array|null
     *
     *   public function latestPost(array $values) {
     *       return $this->hasOneThrough(
     *           Post::class, User::class, $values['id'],
     *           'country_id', 'user_id'
     *       );
     *   }
     */
    public function hasOneThrough(
        string $model,
        string $throughModel,
        string $value,
        string $firstKey,
        string $secondKey,
        ?string $localKey = null,
        ?string $secondLocalKey = null
    ): ?array {
        $instance       = new $model;
        $through        = new $throughModel;
        $finalTable     = $instance->table;
        $throughTable   = $through->table;
        $localKey       = $localKey ?? $this->getPrimary();
        $secondLocalKey = $secondLocalKey ?? $through->getPrimary();

        return $instance
            ->join(null, $model, "{$throughTable}.{$secondLocalKey} = {$finalTable}.{$secondKey}")
            ->whereRaw("{$throughTable}.{$firstKey} = :value", compact('value'))
            ->first();
    }

    // ─────────────────────────────────────────────
    // POLYMORPHIC RELATIONS
    // ─────────────────────────────────────────────

    /**
     * Polymorphic one-to-many. Multiple models share a single table.
     * Example: Post and Video both have comments in one comments table.
     *
     * @param string      $model     Related model class name.
     * @param string      $morphName Morph prefix (e.g. 'commentable' → commentable_id, commentable_type).
     * @param string      $value     Current model's primary key value.
     * @param string|null $type      Morph type identifier. Defaults to static::class.
     * @return array
     *
     *   public function comments(array $values) {
     *       return $this->morphMany(Comment::class, 'commentable', $values['id']);
     *   }
     */
    public function morphMany(string $model, string $morphName, string $value, ?string $type = null): array
    {
        $type = $type ?? static::class;
        return (new $model)
            ->where("{$morphName}_id", $value)
            ->where("{$morphName}_type", $type)
            ->get();
    }

    /**
     * Polymorphic one-to-one. Returns a single related record.
     *
     * @param string      $model     Related model class name.
     * @param string      $morphName Morph prefix.
     * @param string      $value     Current model's primary key value.
     * @param string|null $type      Morph type identifier. Defaults to static::class.
     * @return array|null
     *
     *   public function avatar(array $values) {
     *       return $this->morphOne(Image::class, 'imageable', $values['id']);
     *   }
     */
    public function morphOne(string $model, string $morphName, string $value, ?string $type = null): ?array
    {
        $type = $type ?? static::class;
        return (new $model)
            ->where("{$morphName}_id", $value)
            ->where("{$morphName}_type", $type)
            ->first();
    }

    /**
     * Inverse of a polymorphic relation. Resolves the parent model dynamically.
     *
     * @param array  $values    Row data (must contain {morphName}_type and {morphName}_id).
     * @param string $morphName Morph prefix.
     * @return array|null
     *
     *   public function commentable(array $values) {
     *       return $this->morphTo($values, 'commentable');
     *   }
     */
    public function morphTo(array $values, string $morphName): ?array
    {
        $type = $values["{$morphName}_type"] ?? null;
        $id   = $values["{$morphName}_id"] ?? null;

        if (!$type || !$id || !class_exists($type)) return null;

        $instance = new $type;
        return $instance->where($instance->getPrimary(), $id)->first();
    }

    /**
     * Polymorphic many-to-many through a pivot table.
     * Example: Posts and Videos can both have tags via a taggables pivot table.
     *
     * @param string      $model      Related model class name.
     * @param string      $morphName  Morph prefix.
     * @param string      $value      Current model's primary key value.
     * @param string|null $type       Morph type identifier. Defaults to static::class.
     * @param string|null $pivotTable Pivot table name. Defaults to {morphName}s.
     * @param string|null $foreignKey Morph ID column in pivot. Defaults to {morphName}_id.
     * @param string|null $relatedKey Related model key column in pivot.
     * @return array
     *
     *   public function tags(array $values) {
     *       return $this->morphToMany(Tag::class, 'taggable', $values['id']);
     *   }
     */
    public function morphToMany(
        string $model,
        string $morphName,
        string $value,
        ?string $type = null,
        ?string $pivotTable = null,
        ?string $foreignKey = null,
        ?string $relatedKey = null
    ): array {
        $instance     = new $model;
        $type         = $type ?? static::class;
        $relatedTable = $instance->table;
        $pivotTable   = $pivotTable ?? "{$morphName}s";
        $foreignKey   = $foreignKey ?? "{$morphName}_id";
        $relatedKey   = $relatedKey ?? rtrim($relatedTable, 's') . '_id';

        return $instance
            ->join(null, $model, "{$pivotTable}.{$relatedKey} = {$relatedTable}.{$instance->getPrimary()}")
            ->whereRaw(
                "{$pivotTable}.{$foreignKey} = :morph_id AND {$pivotTable}.{$morphName}_type = :morph_type",
                ['morph_id' => $value, 'morph_type' => $type]
            )
            ->get();
    }

    /**
     * Inverse of morphToMany. Retrieves models related through a polymorphic pivot.
     *
     * @param string      $model      Related model class name.
     * @param string      $morphName  Morph prefix.
     * @param string      $value      Current model's primary key value.
     * @param string|null $pivotTable Pivot table name.
     * @param string|null $foreignKey Current model key column in pivot.
     * @param string|null $relatedKey Morph ID column in pivot.
     * @return array
     *
     *   public function posts(array $values) {
     *       return $this->morphedByMany(Post::class, 'taggable', $values['id']);
     *   }
     */
    public function morphedByMany(
        string $model,
        string $morphName,
        string $value,
        ?string $pivotTable = null,
        ?string $foreignKey = null,
        ?string $relatedKey = null
    ): array {
        $instance     = new $model;
        $relatedTable = $instance->table;
        $pivotTable   = $pivotTable ?? "{$morphName}s";
        $relatedKey   = $relatedKey ?? "{$morphName}_id";
        $foreignKey   = $foreignKey ?? rtrim($this->table, 's') . '_id';

        return $instance
            ->join(null, $model, "{$pivotTable}.{$relatedKey} = {$relatedTable}.{$instance->getPrimary()}")
            ->whereRaw(
                "{$pivotTable}.{$foreignKey} = :fk_val AND {$pivotTable}.{$morphName}_type = :morph_type",
                ['fk_val' => $value, 'morph_type' => $model]
            )
            ->get();
    }

    /**
     * Resolve eager loaded relations onto result set. Should be called inside get().
     *
     * @param array &$results Reference to the fetched result set.
     * @return void
     */
    protected function loadRelations(array &$results): void
    {
        if (empty($this->eagerLoad) || empty($results)) return;

        foreach ($this->earLoad as $relation) {
            if (!method_exists($this, $relation)) continue;
            foreach ($results as &$row) $row[$relation] = $this->{$relation}($row);
        }
    }

    // ─────────────────────────────────────────────
    // RELATION COUNTING & EXISTENCE
    // ─────────────────────────────────────────────

    /**
     * Count related records without loading them.
     *
     * @param string      $model  Related model class name.
     * @param string      $value  Parent's primary key value.
     * @param string|null $column Foreign key column on the related table.
     * @return int
     *
     *   public function postsCount(array $values) {
     *       return $this->hasManyCount(Post::class, $values['id'], 'user_id');
     *   }
     */
    public function hasManyCount(string $model, string $value, ?string $column = null): int
    {
        if (!$column) $column = $this->table . "_id";
        return (new $model)->where($column, $value)->count();
    }

    /**
     * Check if any related record exists.
     *
     * @param string      $model  Related model class name.
     * @param string      $value  Parent's primary key value.
     * @param string|null $column Foreign key column on the related table.
     * @return bool
     *
     *   public function hasPosts(array $values) {
     *       return $this->hasRelation(Post::class, $values['id'], 'user_id');
     *   }
     */
    public function hasRelation(string $model, string $value, ?string $column = null): bool
    {
        return $this->hasManyCount($model, $value, $column) > 0;
    }

    // ─────────────────────────────────────────────
    // PIVOT TABLE OPERATIONS
    // ─────────────────────────────────────────────

    /**
     * Attach a record to a pivot table.
     *
     * @param string $pivotTable   Pivot table name.
     * @param string $foreignKey   Pivot column referencing this model.
     * @param string $foreignValue This model's key value.
     * @param string $relatedKey   Pivot column referencing the related model.
     * @param string $relatedValue Related model's key value.
     * @param array  $extra        Additional columns to insert.
     * @return mixed
     *
     *   $user->attach('user_roles', 'user_id', $userId, 'role_id', $roleId);
     *   $user->attach('user_roles', 'user_id', $userId, 'role_id', $roleId, ['assigned_at' => date('Y-m-d')]);
     */
    public function attach(string $pivotTable, string $foreignKey, string $foreignValue, string $relatedKey, string $relatedValue, array $extra = [])
    {
        $data = array_merge([
            $foreignKey => $foreignValue,
            $relatedKey => $relatedValue,
        ], $extra);

        return (new \zFramework\Core\Facades\DB)->table($pivotTable)->insert($data);
    }

    /**
     * Detach records from a pivot table. Omit relatedKey/Value to remove all.
     *
     * @param string      $pivotTable   Pivot table name.
     * @param string      $foreignKey   Pivot column referencing this model.
     * @param string      $foreignValue This model's key value.
     * @param string|null $relatedKey   Pivot column referencing the related model.
     * @param string|null $relatedValue Related model's key value. Omit both to detach all.
     * @return mixed
     *
     *   $user->detach('user_roles', 'user_id', $userId, 'role_id', $roleId);
     *   $user->detach('user_roles', 'user_id', $userId); // removes all roles
     */
    public function detach(string $pivotTable, string $foreignKey, string $foreignValue, ?string $relatedKey = null, ?string $relatedValue = null)
    {
        $db = (new \zFramework\Core\Facades\DB)->table($pivotTable)->where($foreignKey, $foreignValue);
        if ($relatedKey && $relatedValue) $db->where($relatedKey, $relatedValue);
        return $db->delete();
    }

    /**
     * Sync pivot table: removes all existing and inserts the given IDs.
     *
     * @param string $pivotTable    Pivot table name.
     * @param string $foreignKey    Pivot column referencing this model.
     * @param string $foreignValue  This model's key value.
     * @param string $relatedKey    Pivot column referencing the related model.
     * @param array  $relatedValues Array of related model key values to sync.
     * @param array  $extra         Additional columns to insert with each record.
     * @return void
     *
     *   $user->sync('user_roles', 'user_id', $userId, 'role_id', [1, 2, 3]);
     */
    public function sync(string $pivotTable, string $foreignKey, string $foreignValue, string $relatedKey, array $relatedValues, array $extra = []): void
    {
        $this->detach($pivotTable, $foreignKey, $foreignValue);
        foreach ($relatedValues as $relatedValue) $this->attach($pivotTable, $foreignKey, $foreignValue, $relatedKey, $relatedValue, $extra);
    }

    /**
     * Toggle a pivot record: attach if missing, detach if present.
     *
     * @param string $pivotTable   Pivot table name.
     * @param string $foreignKey   Pivot column referencing this model.
     * @param string $foreignValue This model's key value.
     * @param string $relatedKey   Pivot column referencing the related model.
     * @param string $relatedValue Related model's key value.
     * @param array  $extra        Additional columns to insert if attaching.
     * @return mixed
     *
     *   $user->toggleAttach('user_roles', 'user_id', $userId, 'role_id', $roleId);
     */
    public function toggleAttach(string $pivotTable, string $foreignKey, string $foreignValue, string $relatedKey, string $relatedValue, array $extra = [])
    {
        $db = (new \zFramework\Core\Facades\DB)->table($pivotTable)
            ->where($foreignKey, $foreignValue)
            ->where($relatedKey, $relatedValue);

        if ($db->count() > 0) return $db->delete();

        return $this->attach($pivotTable, $foreignKey, $foreignValue, $relatedKey, $relatedValue, $extra);
    }
}
