<?php

namespace App\Models;

use zFramework\Core\Abstracts\Model;

class Users extends Model
{

    public $db         = "mssql";
    public $table      = "Users";

    public function posts(array $values)
    {
        return $this->hasMany(Posts::class, $values['id'], 'user_id');
    }
}
