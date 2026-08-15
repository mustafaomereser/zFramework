# zFramework — Agent Kuralları

Laravel'e benziyor, Laravel değil. En büyük fark: **satırlar dizi, nesne değil** —
`$post['title']`, `$post->title` değil.

Uygulama kodu yazmadan önce `zframework` skill'ini çağır
(`.claude/skills/zframework/`): API envanteri, doğru imzalar ve "bu zaten var,
yeniden yazma" listesi orada. Aşağıdakiler skill çağrılmasa da geçerli olan
kurallar — hepsi pratikte tekrar tekrar bozulduğu için burada.

## View

- **Yer sabit.** `resource/views/<uygulama>/main.php` o katmanın tek layout'u.
  Her sayfa grubu bir **dizin + `index.php`** — `pages/welcome.php` değil,
  `pages/welcome/index.php`. Create ve edit tek dosya: `edit-or-create.php`.
- **Yeni katman (admin/panel) üç parça birlikte gelir:** `<katman>/main.php`,
  `errors/<katman>/{main,404}.php` ve o katmanı koruyan yetki middleware'i.
  Hangi hata görünümünün kullanılacağını `Http::$error_view` seçiyor.
- **`@extends` / `@section` / `@yield` / `@include` kullan.** Layout'suz sayfa,
  render olsa bile yanlış.
- **Çıktı ve döngüde düz PHP tercih edilir:** `<?= $x ?>`, `<?php foreach (): ?>`,
  `<?php if (): ?>`. Sebep: `{{ }}` **escape etmiyor**, `<?= ?>`'ye derleniyor —
  güvenlik farkı yok, sadece daha kırılgan. Escape gerekiyorsa `<?= e($x) ?>`.
  **Kullanıcı `{{ }}` isterse ona uy**, bu bir tercih, yasak değil.
- **Bu directive'ler framework'te yok**, yazarsan sayfaya harfiyen basılır:
  `@for` `@while` `@switch` `{!! !!}` `{{-- --}}` `@csrf` `@method` `@auth`
  `@guest` `@push` `@stack` `@component` `@each`.
  Karşılıkları: `<?php for (): ?>`, `<?= csrf() ?>`, `<?= inputMethod('PATCH') ?>`.
- Derlenmiş view'ları temizleme: `php terminal cache clear views`.

## Route ve controller

- **CRUD'u elle yazma.** `Route::resource('/posts', PostController::class)` yedi
  route'u adlandırılmış olarak kuruyor. `$crud` gibi bir dizi kurup `foreach` ile
  route üretmek yasak.
- `Route::resource` iki parametre alıyor; `->only()` / `->except()` / `->names()`
  yok. Yıkım metodu **`delete`**, `destroy` değil. Prefix `Route::pre()`,
  `prefix()` değil. `Route::where()` yok.
- **Controller'ı elle yazma:** `php terminal make controller X --resource` tam
  olarak `Route::resource`'un çağırdığı yedi metodu üretiyor.
- **Tek taban sınıf: `zFramework\Core\Abstracts\Controller`** ve bilerek boş.
  `AbstractCrudController` benzeri bir ara katman veya interface uydurma.
- **`php terminal route list` tam liste değil** — şartlı kayıtlı, modül `status`'üne
  bağlı veya `route/dynamic/` altındaki route'lar görünmeyebilir. Gerçek listeyi
  öğrenmek için `route/web.php`, `route/api.php`, `route/dynamic/*` ve etkin
  modüllerin `route/web.php` dosyalarını oku.

## Genel

- Controller `return view(...)` yapar, echo etmez.
- Dosya yükleme `File::upload()` ile — elle `mkdir`/`move_uploaded_file` yok.
- `$_SESSION`'a doğrudan dokunma; `Session::set/get` istek başına tek okuma/yazma
  yapıyor, etrafından dolaşmak bunu bozuyor.
- `errorHandler()` dönüşünü echo etme — sayfayı iki kez basar.
- `zFramework/` altında public yüzeyi değiştiren her iş, **aynı commit'te** skill'i
  de günceller. Hangi değişikliğin hangi dosyaya gittiği:
  `.claude/skills/zframework/references/conventions.md` → "Keeping this skill current".

Tam kurallar ve kopyalanacak iskeletler:
`.claude/skills/zframework/references/views.md` ve `.claude/skills/zframework/templates/`.
