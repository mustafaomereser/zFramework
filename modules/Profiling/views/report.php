<style>
    .zp {
        font-family: ui-monospace, "Cascadia Code", Consolas, monospace;
        background: #0f0f14;
        color: #cdd6f4;
        padding: 24px;
        margin: 0;
        min-height: 100vh;
        font-size: 13px;
        line-height: 1.6
    }

    .zp h1 {
        font-size: 15px;
        margin: 0 0 4px;
        color: #89b4fa;
        font-weight: 600
    }

    .zp .sub {
        color: #6c7086;
        margin-bottom: 20px;
        font-size: 12px
    }

    .zp table {
        border-collapse: collapse;
        width: 100%;
        margin-bottom: 28px
    }

    .zp th {
        text-align: left;
        color: #6c7086;
        font-weight: 500;
        border-bottom: 1px solid #313244;
        padding: 6px 12px 6px 0;
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: .5px
    }

    .zp td {
        padding: 5px 12px 5px 0;
        border-bottom: 1px solid #1e1e2e
    }

    .zp td.n {
        text-align: right;
        font-variant-numeric: tabular-nums;
        color: #fff
    }

    .zp .url {
        color: #a6e3a1
    }

    .zp .slow {
        color: #f38ba8
    }

    .zp .ok {
        color: #a6e3a1
    }

    .zp .dim {
        color: #6c7086
    }

    .zp .off {
        background: #45213a;
        border-left: 3px solid #f38ba8;
        padding: 10px 14px;
        margin-bottom: 20px;
        border-radius: 3px
    }

    .zp a {
        color: #89b4fa
    }

    .zp .bar {
        display: inline-block;
        height: 8px;
        background: #45475a;
        border-radius: 2px;
        vertical-align: middle;
        margin-left: 8px
    }
</style>

<div class="zp">
    <h1>Profiling</h1>
    <div class="sub">
        <?= $total ?> record(s), keeping up to <?= $keep ?>.
        <?php if ($enabled) : ?>Recording <?= $rate >= 1 ? 'every request' : 'one request in ' . round(1 / max((float) $rate, 0.0001)) ?>.<?php endif; ?>
        &nbsp;·&nbsp; <a href="<?= route('profiling.clear') ?>">clear</a>
    </div>

    <?php if (!$enabled) : ?>
        <div class="off">
            Recording is off. Set <b>profiling.enabled</b> in <b>config/framework.php</b> to start.
            Anything below was recorded while it was on.
        </div>
    <?php endif; ?>

    <?php if (!count($summary)) : ?>
        <p class="dim">Nothing recorded yet. Turn recording on, load a few pages, come back.</p>
    <?php else : ?>

        <table>
            <tr>
                <th>url</th>
                <th class="n">runs</th>
                <th class="n">best</th>
                <th class="n">median</th>
                <th class="n">worst</th>
                <th class="n">boot avg</th>
                <th class="n">memory</th>
                <th class="n">files</th>
                <th></th>
            </tr>
            <?php $slowest = max(array_column($summary, 'median_ms')); ?>
            <?php foreach ($summary as $row) : ?>
                <tr>
                    <td class="url"><?= $row['url'] ?></td>
                    <td class="n dim"><?= $row['runs'] ?></td>
                    <td class="n ok"><?= $row['best_ms'] ?></td>
                    <td class="n"><b><?= $row['median_ms'] ?></b></td>
                    <td class="n <?= $row['worst_ms'] > $row['best_ms'] * 3 ? 'slow' : 'dim' ?>"><?= $row['worst_ms'] ?></td>
                    <td class="n dim"><?= $row['boot_ms'] ?></td>
                    <td class="n dim"><?= $row['memory_mb'] ?> MB</td>
                    <td class="n dim"><?= $row['files'] ?></td>
                    <td><span class="bar" style="width: <?= max(2, round($row['median_ms'] / max($slowest, 0.001) * 120)) ?>px"></span></td>
                </tr>
            <?php endforeach; ?>
        </table>

        <div class="sub">
            Median, not average — one request that waited on a busy disk drags a mean
            somewhere no request actually went. <b>best</b> is closest to what the work
            costs; the gap to <b>worst</b> is how much the machine is interfering.
            <b>boot</b> is everything before the route was matched.
        </div>

        <table>
            <tr>
                <th>when</th>
                <th>url</th>
                <th class="n">boot</th>
                <th class="n">handle</th>
                <th class="n">controller</th>
                <th class="n">view</th>
                <th class="n">total</th>
                <th class="n">status</th>
                <th>env</th>
            </tr>
            <?php foreach ($recent as $row) : ?>
                <tr>
                    <td class="dim"><?= $row['at'] ?></td>
                    <td class="url"><?= $row['url'] ?></td>
                    <td class="n dim"><?= $row['boot_ms'] ?></td>
                    <td class="n dim"><?= $row['handle_ms'] ?></td>
                    <td class="n dim"><?= $row['controller_ms'] ?? '-' ?></td>
                    <td class="n dim"><?= $row['view_ms'] ?? '-' ?></td>
                    <td class="n"><b><?= $row['total_ms'] ?></b></td>
                    <td class="n <?= ($row['status'] ?? 200) >= 400 ? 'slow' : 'dim' ?>"><?= $row['status'] ?? '-' ?></td>
                    <td class="dim">
                        <?= ($row['opcache'] ?? false) ? 'opcache' : '<span class="slow">no opcache</span>' ?>
                        <?= ($row['apcu'] ?? false) ? ' · apcu' : '' ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>

        <div class="sub">
            <b>boot</b> is the framework getting ready — bootstrap, route table, providers,
            modules, global middlewares. <b>handle</b> is everything after: matching the
            route, the controller, rendering, sending the response.
            <b>controller</b> and <b>view</b> come from inside handle, and view is
            <i>part of</i> controller rather than additional to it — a controller that
            returns a view spends most of its time there. handle minus controller is the
            framework's own share: matching, and the response.
        </div>

    <?php endif; ?>
</div>