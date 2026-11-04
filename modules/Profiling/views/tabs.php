<nav class="tabs">
    <a href="<?= route('profiling.index') ?>" class="<?= isset($locked) ? '' : 'on' ?>">Requests</a>
    <a href="<?= route('profiling.queries') ?>" class="<?= isset($locked) ? 'on' : '' ?>">Queries</a>
</nav>
