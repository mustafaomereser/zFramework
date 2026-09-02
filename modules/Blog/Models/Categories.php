<?php

namespace modules\Blog\Models;

use zFramework\Core\Abstracts\Model;

#[\AllowDynamicProperties]
class Categories extends Model
{
    public $table = "categories";

    public function posts(array $values)
    {
        return $this->findRelation(BlogToCategories::class, $values['id'], 'category_id');
    }

    public function parent(array $values)
    {
        return $this->hasOne(Categories::class, $values['parent_id'], 'id');
    }

    public function childrens(array $values)
    {
        return $this->findRelation(Categories::class, $values['id'], 'parent_id');
    }
}
