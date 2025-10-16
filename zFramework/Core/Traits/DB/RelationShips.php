<?php

namespace zFramework\Core\Traits\DB;

trait RelationShips
{
    public function findRelation(string $model, string $value, ?string $column = null)
    {
        if (!$column) $column = $this->table . "_id";
        return model($model)->where($column, $value);
    }

    public function hasMany(string $model, string $value, ?string $column = null)
    {
        return $this->findRelation($model, $value, $column)->get();
    }

    public function hasOne(string $model, string $value, ?string $column = null)
    {
        return $this->findRelation($model, $value, $column)->first();
    }
}
