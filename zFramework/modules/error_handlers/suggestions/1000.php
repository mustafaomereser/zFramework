<?php
if (isset($_GET['crypt-key-create'])) {
    \zFramework\Kernel\Terminal::begin(["terminal", "security key --regen"]);
    refresh();
}
?>

<div class="error-type">⚠️ Her proje için benzersiz bir şifreleme anahtarı oluşturmanız gerekir.</div>
<div class="error-description">
    <a href="<?= host() . uri() ?>?crypt-key-create=true" class="ide-button">Şifreleme Dosyasını Oluştur</a>
    <div style="margin-top: 10px">
        Eğer buton işe yaramıyor ise, Terminalde Komut şudur: <kbd>php terminal security key --regen</kbd>
    </div>
</div>