<?php

namespace modules\Blog\Models;

use zFramework\Core\Abstracts\Model;

#[\AllowDynamicProperties]
class BlogToCategories extends Model
{
    public $table = "blog_to_categories";

    public function post(array $values)
    {
        return $this->hasOne(Blogs::class, $values['post_id'], 'id');
    }

    public function category(array $values)
    {
        return $this->hasOne(Categories::class, $values['category_id'], 'id');
    }
}
