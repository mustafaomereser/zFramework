<?php

namespace App\Models;

use App\Observers\UserObserver;
use zFramework\Core\Abstracts\Model;
use zFramework\Core\Traits\DB\softDelete;

class User extends Model
{
    use softDelete;

    // public $observe      = UserObserver::class;

    public $table      = "users";
    public $_not_found = 'User is not found.';
    // public $guard    = ['password', 'api_token'];

    # every reset after begin query.
    public function beginQuery()
    {
        // return $this->where('id', 1);
    }

    public function posts(array $data)
    {
        return $this->hasMany(Posts::class, $data['id'], 'user_id');
    }
}
