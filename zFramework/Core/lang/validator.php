<?php

/**
 * What a rule says when the application's resource/lang/<locale>/validator.php
 * has no message for it - see lang/errors.php for why the core carries these.
 * A rule added in a later version arrives with its message here.
 */

return [
    'errors' => [
        'required'  => 'is required.',
        'email'     => 'must be a valid e-mail address.',
        'type'      => 'is {now-type}, but must be {must-type}.',
        'max'       => 'is {now-val}, but may be at most {max-val}.',
        'min'       => 'is {now-val}, but must be at least {min-val}.',
        'same'      => 'does not match {attribute-name}.',
        'unique'    => 'is already in use.',
        'exists'    => 'does not exist.',
        'in'        => 'is not in the list. accepted: {allowed}.',
        'not-in'    => 'cannot be used. blocked: {blocked}.',
        'regex'     => 'is not in the expected format.',
        'url'       => 'must be a valid http or https address.',
        'date'      => 'is not a valid date.',
        'between'   => 'is {now-val}, but must be between {min-val} and {max-val}.',
        'length'    => 'is {now-val} characters long, but must be {length-val}.',
        'confirmed' => 'does not match {other}.',
    ],
];
