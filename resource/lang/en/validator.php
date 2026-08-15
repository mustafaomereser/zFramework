<?php

return [
    'errors' => [
        'required'  => 'is required.',
        'email'     => 'must be a acceptable E-mail adress.',
        'type'      => 'your type is {now-type} but here is only accept {must-type}.',
        'max'       => 'your value is {now-val} but you must max submit {max-val} value.',
        'min'       => 'your value is {now-val} but you must min submit {min-val} value.',
        'same'      => 'value is not match {attribute-name}',
        'unique'    => 'already using.',
        'exists'    => 'that\'s not exists.',
        'in'        => 'is not in the list. accepted: {allowed}.',
        'not-in'    => 'cannot be used.',
        'regex'     => 'is not in the expected format.',
        'url'       => 'must be a valid http or https address.',
        'date'      => 'is not a valid date.',
        'between'   => 'is {now-val}, but must be between {min-val} and {max-val}.',
        'confirmed' => 'does not match {other}.',
    ],

    'attributes' => [
        'username'    => 'Username',
        'password'    => 'Password',
        're-password' => 'Repeat Password',
        'email'       => 'E-mail',
        'terms'       => 'User Agreement Policy'
    ]
];
