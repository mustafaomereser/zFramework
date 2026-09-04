<?php

/**
 * MongoDB connections - the sibling of connections.php, same shape of thinking:
 * the first entry is the default, a model picks another with
 * `public $connection = 'name';`. An empty array means Mongo is off and
 * nothing mongo-related is ever loaded.
 *
 * Needs the mongodb extension (php.ini: extension=mongodb). Models extend
 * zFramework\Core\Abstracts\MongoModel and live in App/Models beside the SQL
 * models - see README 2.9.
 */
return [
    // 'mongo' => [
    //     'uri'      => 'mongodb://127.0.0.1:27017',
    //     'database' => 'z_framework',
    // ],
];
