<?php

namespace App\Models;

use zFramework\Core\Abstracts\Model;

#[\AllowDynamicProperties]
class SystemDbCollector extends Model
{
    public $table = "system_db_collector";

    public function beginQuery() 
    {
        // return $this->where();
    }
}
