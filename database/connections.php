<?php
return [
    'local' => ['mysql:host=127.0.0.1;port=3306;dbname=z_framework;charset=utf8mb4', 'root', '', 'options' => [
        [\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION],
        [\PDO::ATTR_EMULATE_PREPARES, true], # for PDO lastInsertId method.

        # Reuse the connection across requests instead of a handshake and auth
        # round-trip each time. Worth most where the database is on another
        # machine; over a unix socket the saving is small.
        #
        # Count first: one connection per FPM worker per entry in this file stays
        # open even while idle, so 50 workers across 5 tenants holds 250 against
        # max_connections. `php terminal bench run` reports what it saves here.
        [\PDO::ATTR_PERSISTENT, true],
    ]]
];