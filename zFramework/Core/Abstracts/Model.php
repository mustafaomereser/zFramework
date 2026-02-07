<?php

namespace zFramework\Core\Abstracts;

use zFramework\Core\Facades\DB;

abstract class Model extends DB
{
    /**
     * Usual Parameters for organize.
     */
    public $primary      = null;
    public $guard        = [];
    public $closures     = [];
    public $created_at;
    public $updated_at;
    public $deleted_at;
    public $deleted_at_type;
    public $not_closures  = ['beginQuery'];
    public $_not_found    = 'Not found.';

    /**
     * Run parent construct and set table.
     */
    public function __construct()
    {
        foreach (config('model.consts') as $key => $val) $this->{$key} = $val;
        $this->deleted_at_type = config('model.deleted_at_type');

        parent::__construct(@$this->db);
        parent::table($this->table);
    }
}
