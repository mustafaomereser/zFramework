<?php

/**
 * Layout skeleton for one interface layer.
 *
 * Copy to resource/views/<app>/main.php — one per layer (app, admin, panel), never a
 * variant of an existing one. Pages reach it with @extends('<app>.main').
 *
 * The three @yield names below are the contract every page in this layer relies on.
 * Renaming one means editing every page, so keep header/body/footer.
 *
 * This file fetches NOTHING. Anything the layout needs on every render is bound in
 * App/Providers/ViewProvider.php and arrives as a variable:
 *
 *     View::bind('app.main', fn() => ['lang_list' => Lang::list()]);
 *
 * The bind fires even when the request rendered a page that @extends this layout, and
 * it re-runs on a cache hit — so there is never a reason to query from in here.
 */

use zFramework\Core\Facades\Alerts;
use zFramework\Core\Facades\Lang;

?>
<!DOCTYPE html>
<html lang="<?= Lang::$locale ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= config('app.title') ?></title>

    <link rel="stylesheet" href="<?= asset('/assets/css/style.css') ?>" />
    @yield('header')
</head>

<body>
    <div class="container">
        @yield('body')
    </div>

    <script src="<?= asset('/assets/js/main.js') ?>"></script>
    <script>
        $.showAlerts(<?= json_encode(Alerts::get()) ?>);
    </script>
    @yield('footer')
</body>

</html>
