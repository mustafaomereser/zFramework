<?php

/**
 * Layout skeleton for one interface layer.
 *
 * Copy to resource/views/<app>/main.php — one per layer (app, admin, panel), never a
 * variant of an existing one. Pages reach it with @extends('<app>.main').
 *
 * The three @yield names below are the contract every page in this layer relies on.
 * Renaming one means editing every page, so keep header/body/footer.
 */

use zFramework\Core\Facades\Alerts;
use zFramework\Core\Facades\Lang;

?>
<!DOCTYPE html>
<html lang="<?= Lang::$locale ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= config('app.name') ?></title>

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
