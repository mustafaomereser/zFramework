<?php

if (isset($_GET['migrate-it'])) {
    \zFramework\Kernel\Terminal::begin(array_merge(["terminal", "db migrate"], (isset($_GET['all']) ? ['--all', '--force'] : [])));
    refresh();
}
?>

<div class="error-type">⚠️ Tablo veritabanında bulunmuyor gibi görünüyor eğer migrasyon dosyanız var ise migrasyon almanız işe yarayabilir.</div>
<div class="error-description">
    <div style="margin-bottom: 10px;">
        Migrasyon almak için butona basabilirsiniz: <a href="<?= method() == 'GET' ? host() . uri() : null ?>?migrate-it=true" class="ide-button"><kbd>php terminal db migrate</kbd></a>
    </div>
    <div style="margin-bottom: 10px;">
        Eğer sorun hala devam ediyorsa <a href="<?= method() == 'GET' ? host() . uri() : null  ?>?migrate-it=true&all=true" class="ide-button"><kbd>php terminal db migrate --all --force</kbd></a> seçeneğini kullanabilirsiniz.
    </div>

    <div>
        Eğer butonlar çalışmıyorsa buton içindeki komut isteğini KOMUT İSTEMİ üzerinden çalıştırabilirsiniz.
    </div>
</div>