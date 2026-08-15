<?php

namespace App\Providers;

use zFramework\Core\Facades\Lang;
use zFramework\Core\View;

class ViewProvider
{
    public function __construct()
    {
        # Whatever the layout needs on every render is bound here, not fetched inside
        # main.php. A layout that queries or computes cannot be rendered from a second
        # controller without repeating the work, and the query hides somewhere nobody
        # looks for one.
        #
        # A bind runs for the layout even when the request rendered a page that
        # @extends it, and it re-runs on a cache hit, so the data is always fresh.
        View::bind('app.main', fn() => [
            'lang_list' => Lang::list(),
        ]);
    }
}
