<?php

return [
    'consts' => [
        'created_at' => 'created_at',
        'updated_at' => 'updated_at',
        'deleted_at' => 'deleted_at'
    ],

    /**
     * For boolean terms, terms like "status" are often preferred because the control is reversed; 
     * for example, a record of 1 means the record has not been deleted, and a record of 0 means it has been deleted.
     */
    'deleted_at_type' => ['date', 'bool'][0]
];
