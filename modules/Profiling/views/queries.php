@include('Profiling.views.style')

<div class="zp">
    @include('Profiling.views.tabs')

    <?php if ($locked) : ?>
        <div class="off">This page needs <b>debug</b> on. It shows what is queried and from where, and it has no login.</div>
    <?php else : ?>
        <h1>Queries</h1>
        <div class="sub">
            <?= $records ?> analysed quer<?= $records === 1 ? 'y' : 'ies' ?>, <?= count($summary) ?> shape(s), store: <b><?= e($store) ?></b>.
            <?php if ($records >= $limit) : ?>Reading the newest <?= $limit ?>.<?php endif; ?>
        </div>

        <?php if ($error) : ?>
            <div class="off">Could not read the store: <?= e($error) ?>. With queryStore <b>table</b>, run <b>php terminal db migrate</b> first.</div>
        <?php endif; ?>

        <?php if (!$analyzing) : ?>
            <div class="off">The analyzer is off. Set <b>profiling.queryAnalyze</b> in <b>config/framework.php</b> to start. Anything below was recorded while it was on.</div>
        <?php else : ?>
            <div class="warn">The analyzer is on: every analysed query runs twice, so the request times on the Requests tab are higher than real ones. Query times here are the query's own.</div>
        <?php endif; ?>

        <?php if ($full) : ?>
            <div class="off">The store holds <?= $keep ?> requests (<b>profiling.keep</b>), so new ones are not being recorded. Clear it to continue.</div>
        <?php endif; ?>

        <?php if ($records) : ?>
            <form method="POST" action="<?= route('profiling.queries.clear') ?>" class="clear">
                <?= csrf() ?>
                <button type="submit">clear</button>
            </form>
        <?php endif; ?>

        <?php if (!count($summary)) : ?>
            <p class="dim">Nothing recorded yet. Turn the analyzer on, load a few pages, come back.</p>
        <?php else : ?>

            <h2>Index suggestions</h2>
            <?php if (count($suggestions)) : ?>
                <table>
                    <tr>
                        <th>statement</th>
                        <th class="n">queries</th>
                        <th class="n">runs</th>
                        <th class="n">total</th>
                    </tr>
                    <?php foreach ($suggestions as $suggestion) : ?>
                        <tr>
                            <td><code><?= e($suggestion['statement']) ?></code></td>
                            <td class="n dim"><?= $suggestion['queries'] ?></td>
                            <td class="n dim"><?= $suggestion['runs'] ?></td>
                            <td class="n"><b><?= $suggestion['total_ms'] ?></b> ms</td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php else : ?>
                <p class="dim">None.</p>
            <?php endif; ?>
            <div class="sub">
                Advice to weigh, not to run: add what makes sense through a migration, then clear and measure again. An index costs every write to the table.
                <?php if ($small) : ?>
                    <a href="<?= route('profiling.queries') ?>">hide small tables</a>
                <?php elseif ($hidden > 0) : ?>
                    <?= $hidden ?> for tables under 5000 rows hidden, <a href="<?= route('profiling.queries') ?>?small=1">show</a>.
                <?php endif; ?>
            </div>

            <h2>Queries</h2>
            <table>
                <tr>
                    <th>query</th>
                    <th class="n">runs</th>
                    <th class="n">total</th>
                    <th class="n">avg</th>
                    <th class="n">worst</th>
                    <th class="n">scanned</th>
                    <th class="n">returned</th>
                    <th>access</th>
                </tr>
                <?php foreach ($summary as $query) : ?>
                    <tr>
                        <td class="q">
                            <details>
                                <summary><?= e($query['query']) ?></summary>
                                <dl>
                                    <dt>tables</dt>
                                    <dd><?= e(implode(', ', $query['tables'])) ?: '-' ?></dd>
                                    <dt>indexes</dt>
                                    <dd><?= e(implode(', ', $query['indexes'])) ?: 'none' ?></dd>
                                    <dt>warnings</dt>
                                    <dd><?= e(implode(', ', $query['warnings'])) ?: '-' ?></dd>
                                    <dt>suggestions</dt>
                                    <dd><?php foreach ($query['suggestions'] as $statement) : ?><code><?= e($statement) ?></code><br><?php endforeach; ?><?= count($query['suggestions']) ? '' : '-' ?></dd>
                                    <dt>called from</dt>
                                    <dd><?php foreach ($query['urls'] as $url => $count) : ?><span class="url"><?= e($url) ?></span> <span class="dim">&times;<?= $count ?></span><br><?php endforeach; ?></dd>
                                </dl>
                            </details>
                        </td>
                        <td class="n dim"><?= $query['runs'] ?></td>
                        <td class="n"><b><?= $query['total_ms'] ?></b></td>
                        <td class="n dim"><?= $query['avg_ms'] ?></td>
                        <td class="n dim"><?= $query['worst_ms'] ?></td>
                        <td class="n <?= $query['returned'] !== null && $query['scanned'] > max($query['returned'], 1) * 10 ? 'slow' : 'dim' ?>"><?= $query['scanned'] ?></td>
                        <td class="n dim"><?= $query['returned'] ?? '-' ?></td>
                        <td class="<?= $query['full_scan'] && !$query['small'] ? 'slow' : 'dim' ?>"><?= $query['full_scan'] ? 'full scan' : 'index' ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
            <div class="sub">
                Grouped by shape: values, placeholder names and the length of an IN list do not count. Most total time first. Times in ms; scanned and returned are per run, red when ten times what came back. The statement is shown as written, without its values: those stay in the store.
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
