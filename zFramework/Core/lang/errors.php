<?php

/**
 * The core's own wording for the errors it raises, in English.
 *
 * resource/lang/<locale>/errors.php is the application's and wins. This is what
 * Lang::get() falls back to for a key the application's file does not have - a
 * project older than the key, which `update` never adds since resource/lang is
 * not the core's to write.
 */

return [
    'csrf' => [
        'no-verify' => 'The form has expired or was not sent from this site. Reload the page and try again.',
    ],

    'file' => [
        'type' => 'Only {file_types} files are accepted.',
        'size' => 'The file is {current-size}; at most {accept-size} is accepted.',
    ],

    'mail' => [
        'sending-is-false'  => 'Sending mail is turned off.',
        'not-validate-mail' => 'That is not a valid e-mail address.',
        'must-set-a-mail'   => 'A recipient address is required.',
    ],
];
