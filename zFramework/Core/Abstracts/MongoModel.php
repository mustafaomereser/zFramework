<?php

namespace zFramework\Core\Abstracts;

use zFramework\Core\Facades\Mongo;

/**
 * A collection as a model - the Mongo counterpart of Abstracts\Model, and
 * just as thin: the properties below, a constructor, nothing else. Every
 * verb (where, get, insert, relations, paginate ...) lives in Facades\Mongo,
 * exactly as Model's live in Facades\DB.
 *
 *   class Log extends MongoModel
 *   {
 *       public $collection = 'logs';
 *       public $connection = 'mongo';     // entry in database/mongoconnections.php; null = first
 *
 *       public function user($row) { return $this->belongsTo(User::class, $row['user_id']); }
 *   }
 *
 * Lives in App/Models beside the SQL models; the extends is the only
 * difference in placement. Relations may point at another MongoModel or at
 * an SQL Model - see Traits\Mongo\RelationShips.
 */
#[\AllowDynamicProperties]
abstract class MongoModel extends Mongo
{
    /**
     * Collection name. Required.
     */
    public $collection;

    /**
     * Connection entry name; null means the first entry - as $db on Model.
     */
    public $connection;

    /**
     * Database name; null means the connection entry's own.
     */
    public $database;

    /**
     * Fields left out of get()/first() when the query names none itself.
     */
    public $guard = [];

    /**
     * Observer class - oninsert(ed) / onupdate(d) / ondelete(d), as on Model.
     */
    public $observe;

    public function __construct()
    {
        if (!$this->collection) throw new \Exception(static::class . ': set public $collection.');
        parent::__construct($this->connection);
    }
}
