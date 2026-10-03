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
     * Column settings a model class redeclares, per class.
     */
    private static array $ownColumns = [];

    /**
     * Which of the timestamp/soft-delete settings a model declares itself.
     *
     * Reflection once per class per process, not per instance.
     *
     * @param string $class
     * @return array name => true
     */
    private static function ownColumns(string $class): array
    {
        if (isset(self::$ownColumns[$class])) return self::$ownColumns[$class];

        $own = [];
        foreach (['created_at', 'updated_at', 'deleted_at', 'deleted_at_type'] as $name)
            if ((new \ReflectionProperty($class, $name))->getDeclaringClass()->getName() !== self::class) $own[$name] = true;

        return self::$ownColumns[$class] = $own;
    }

    /**
     * Run parent construct and set table.
     */
    public function __construct()
    {
        # config/model.php is the default; a model that declares one of these
        # itself keeps its own - `public $deleted_at = 'removed_at'`, or null to
        # turn the column off. Assigned unconditionally, the documented per-model
        # override was overwritten in every constructor and never took effect.
        $own = self::ownColumns(static::class);
        foreach ((array) config('model.consts') as $key => $val) if (!isset($own[$key])) $this->{$key} = $val;

        # 'date' when the key is missing, which is what soft delete always wrote
        # before the key existed. A 2.x config/model.php carries no such key, and
        # null here made delete() write NULL - the row was "deleted" and stayed live.
        if (!isset($own['deleted_at_type'])) $this->deleted_at_type = config('model.deleted_at_type') ?? 'date';

        parent::__construct(@$this->db);
        parent::table($this->table);
    }
}
