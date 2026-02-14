<?php
return [
    'local' => ['mysql:host=127.0.0.1;port=3306;dbname=z_framework;charset=utf8mb4', 'root', '', 'options' => [
        [\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION],
        [\PDO::ATTR_EMULATE_PREPARES, true], # for performance and PDO lastInsertId method.
        // [\PDO::ATTR_PERSISTENT, true],
    ]],
];
