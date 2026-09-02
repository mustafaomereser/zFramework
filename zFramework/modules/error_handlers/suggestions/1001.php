<?php

if (isset($_GET['migrate-it'])) {
    \zFramework\Kernel\Terminal::begin('db migrate' . (isset($_GET['all']) ? ' --all --force' : ''));
    # Back to the page without the flag. refresh() reloaded this same url, flag
    # included, so the command ran again on every load and never let go.
    redirect(strtok(uri(), '?') ?: '/');
}

$here = method() == 'GET' ? host() . uri() : null;
?>
<p>The table is not in the database. If a migration for it exists, running it is usually the whole fix.</p>
<p style="margin:8px 0 0">
    <a href="<?= $here ?>?migrate-it=true" class="btn">php terminal db migrate</a>
    <a href="<?= $here ?>?migrate-it=true&amp;all=true" class="btn" style="margin-left:6px">db migrate --all --force</a>
    <span style="margin-left:10px;color:var(--fg-3)">or run either from a terminal</span>
</p>
