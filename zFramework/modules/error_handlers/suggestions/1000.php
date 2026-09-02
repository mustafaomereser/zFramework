<?php
if (isset($_GET['crypt-key-create'])) {
    \zFramework\Kernel\Terminal::begin('security key --regen');
    refresh();
}
?>
<p>Every installation needs its own encryption key, and this one has none yet.</p>
<p style="margin:8px 0 0"><a href="<?= host() . uri() ?>?crypt-key-create=true" class="btn">Generate the key now</a>
<span style="margin-left:10px;color:var(--fg-3)">or run <code>php terminal security key --regen</code></span></p>
