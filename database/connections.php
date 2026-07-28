<?php
return [
    'local' => ['mysql:host=127.0.0.1;port=3306;dbname=z_framework;charset=utf8mb4', 'root', '', 'options' => [
        [\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION],
        [\PDO::ATTR_EMULATE_PREPARES, true], # for PDO lastInsertId method.

        # Keep the connection open between requests instead of paying for a TCP
        # handshake and an auth round-trip on every one of them. Measured against a
        # local MariaDB 10.4 over 127.0.0.1, so with no network in the way at all:
        # opening a connection costs 6.4 ms, reusing one costs 0.3 ms. That is ~6 ms
        # a request, per entry in this file - twenty times what a SELECT of twenty
        # rows costs. On a remote database server it is larger still.
        #
        # Do the arithmetic before enabling it: one connection per FPM worker per
        # entry here stays open even while idle, so 50 workers across 5 tenants is
        # 250 connections held against max_connections. That, and not the speed, is
        # what decides whether this is a good idea for a given deployment.
        [\PDO::ATTR_PERSISTENT, true],
    ]]
];