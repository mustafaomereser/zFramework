<?php

namespace App\Models;

use zFramework\Core\Abstracts\Model;
use zFramework\Core\Traits\DB\softDelete;

class User extends Model
{
    use softDelete;

    // public $observe      = App\Observers\UserObserver::class;

    public $table       = "users";
    public $_not_found  = 'User is not found.';
    // public $guard    = ['password', 'api_token'];

    # custom columns for Auth::class
    public $special_columns = [
        'email'          => 'email',
        'password'       => 'password',
        'passwordencode' => 'crypter' # crypter | md5
    ];

    /**
     * Optionally model construct.
     * 
     * Allows passing custom parameters when initializing the model.
     *
     * @param string|null $db
     */
    public function __construct(?string $db = null)
    {
        if ($db !== null) $this->db = $db;
        parent::__construct(); # <-- Ensure base model initialization runs properly.
    }

    # every re set after begin query.
    public function beginQuery()
    {
        // return $this->where('id', 1);
    }

    public function posts(array $data)
    {
        return $this->hasMany(Posts::class, $data['id'], 'user_id');
    }

    # you can use special parameters and closures parameters go to end line.
    # public function posts(string $special_parameters = "hello", array $data)
    # {
    #     return $this->hasMany(Posts::class, $data['id'], 'user_id');
    # }
}
