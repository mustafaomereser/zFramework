<?php

return [
    'debug'       => true, # turn false on production.

    # 'analyze' and 'error' moved to config/framework.php - they belong with the
    # other framework behaviour, and are still read from here if you have not moved them.

    'force-https'      => false, # force redirect https.
    'x-powered-by'     => true,  # set false to hide X-Powered-By response header.

    'lang'        => 'tr', # if browser haven't language in Languages list auto choose that default lang.
    'title'       => 'zFramework',
    'public'      => 'public_html',
    'version'     => '1.0.0',

    'pagination' => [
        'default-view' => 'layouts.pagination.default'
    ]
];
