<?php

namespace App\Models;

use zFramework\Core\Abstracts\Model;
use zFramework\Core\Traits\DB\softDelete;

class Posts extends Model
{
    use softDelete;
    public $table = "posts";
}
