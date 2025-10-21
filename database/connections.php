<?php
return [
    'local' => ['mysql:host=localhost;dbname=z_framework;charset=utf8mb4', 'root', '', 'options' => [
        [\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION],
        [\PDO::ATTR_EMULATE_PREPARES, true] # for PDO lastInsertId method.
    ]],
];
