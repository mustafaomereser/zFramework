<?php

return [
    # 'file'  - PHP's default, sessions on this server's disk. Correct for a
    #           single server, and the reason a second one cannot simply be added:
    #           a user whose next request lands elsewhere is no longer logged in.
    #
    # 'redis' - sessions in Redis, shared by every server. This is what makes
    #           horizontal scaling possible; connection details come from
    #           config/redis.php.
    'driver' => 'file',

    # Only used by the file driver. PHP's garbage collector scans this directory,
    # which gets expensive once it holds a lot of files - another reason the file
    # driver does not scale.
    'gc_probability' => 1,
];
