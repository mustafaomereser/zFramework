<?php

namespace modules\Blog\Models;

use App\Models\User;
use zFramework\Core\Abstracts\Model;

#[\AllowDynamicProperties]
class Blogs extends Model
{
    public $table = "blogs";

    public function author(array $values)
    {
        return $this->hasOne(User::class, $values['user_id'], 'id');
    }

    public function categories(array $values)
    {
        return $this->findRelation(BlogToCategories::class, $values['id'], 'post_id');
    }
}
