<?php

return [
    'sending'  => true,

    /**
     * Hand mails to the queue instead of sending them inside the request.
     *
     * SMTP costs 100-1000ms and blocks a PHP worker for all of it. With this on,
     * Mail::send() pushes the job and returns; `php terminal queue work` delivers
     * it. Requires Redis - without it mails are sent inline exactly as before.
     *
     * Note that send() then returns true for "queued", not "delivered".
     * Mail::sendNow() always bypasses the queue.
     */
    'queue'    => false,

    'debug'    => false,
    'SMTPAuth' => true,

    'security' => 'ssl',
    'mail'     => 'mail.server.com',
    'port'     => 443,
    'username' => 'test',
    'password' => 'test',


    'subject' => '',
    'from'    => ['First Last', 'from@example.com'],
    'reply'   => ['First Last', 'replyto@example.com']
];
