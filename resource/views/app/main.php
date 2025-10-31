<?php

use zFramework\Core\Facades\Lang;

$lang_list = Lang::list();
?>
<!DOCTYPE html>
<html lang="<?= Lang::$locale ?>" data-bs-theme="light">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>zFramework</title>

    <link rel="stylesheet" href="https://pro.fontawesome.com/releases/v5.15.4/css/all.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" />
    <link rel="stylesheet" href="<?= asset('/assets/libs/notify/style.auto.min.css') ?>" />
    <link rel="stylesheet" href="<?= asset('/assets/css/style.auto.min.css') ?>" />
    @yield('header')
</head>

<body>
    <div class="container my-lg-5 my-2">
        <div class="row align-items-center">
            <div class="col-md-3 col-12 mb-md-0 mb-4">
                <img src="/assets/images/zframework-transparent.png" alt="zFramework" class="w-100">
            </div>
            <div class="col-md-6 col-6">
                <div class="d-flex align-items-center justify-content-center gap-2">
                    <a class="btn btn-sm border" href="/">
                        <i class="fad fa-home"></i> <?= _l('lang.home-page') ?>
                    </a>
                    <?php foreach ($GLOBALS['menu'] ?? [] as $module => $menu) : ?>
                        <a class="btn btn-sm border" href="<?= $menu['route'] ?>">
                            <i class="<?= $menu['icon'] ?>"></i> <?= $menu['title'] ?>
                        </a>
                    <?php endforeach ?>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="d-flex align-items-center justify-content-md-end justify-content-center gap-2">
                    <div id="auth-content"></div>

                    <div class="btn-group">
                        <button class="btn btn-sm border dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" style="width: 100px">
                            {{ _l('lang.languages') }}
                        </button>
                        <ul class="dropdown-menu">
                            @foreach($lang_list as $lang)
                            <li>
                                <a class="dropdown-item {{ Lang::currentLocale() == $lang ? 'active' : null }}" href="{{ route('language', ['lang' => $lang]) }}">
                                    {{ config("languages.$lang") }}
                                </a>
                            </li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        @yield('body')

        <div class="row mb-3">
            <div class="col-lg-6 col-12 mb-md-0 mb-3">
                <div class="d-flex align-items-center justify-content-lg-start justify-content-center gap-2">
                    <a href="/api/v1">API</a>
                    <a href="https://github.com/mustafaomereser/Z-Framework-php-mvc" target="_blank">Github & Docs</a>
                </div>
            </div>
            <div class="col-lg-6 col-12">
                <div class="d-flex align-items-center justify-content-lg-end justify-content-center gap-2">
                    <small data-toggle="tooltip" title="zFramework Version"><b>zFramework</b> v{{ FRAMEWORK_VERSION }}</small>
                    <small data-toggle="tooltip" title="PHP Version">| <b>PHP</b> v{{ PHP_VERSION }}</small>
                    <small data-toggle="tooltip" title="Current Project Version">| <b>APP</b> v{{ config('app.version') }}</small>
                </div>
            </div>
        </div>
    </div>

    <div id="load-modals"></div>

    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?= asset('/assets/js/main.auto.min.js') ?>"></script>
    <script src="<?= asset('/assets/libs/notify/script.auto.min.js') ?>"></script>
    <script>
        $.showAlerts(<?= json_encode(\zFramework\Core\Facades\Alerts::get()) ?>);
    </script>
    @yield('footer')
</body>

</html>