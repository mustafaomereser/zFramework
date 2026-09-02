# Backlog

Denetim bulgularının uygulama listesi. Ayrıntılar `Son-rapor.md`'de, ham kanıtlar
`Son-rapor-ham-kanit.md`'de.

Sıra: üstten aşağı. Biten madde `[x]` işaretlenir, commit hash'i yazılır.

---

## 1. View derleyicisi

- [x] **`View.php:419` — minify `<script src="//cdn…">` olan sayfayı yok ediyor**
      `(?<!:)` guard'ı yalnızca `https://` gibi şema önekli hâlleri kurtarıyor, `/s` bayrağı
      olmadığı için `//`'dan satır sonuna kadar her şey siliniyor — kapanış etiketi dahil.
      Sayfanın kalanı script gövdesine giriyor. Aynısı JS regex literalinde (`/\/\//g`) ve
      JS string'i içindeki `'//example.com'` ifadesinde.
      Ölçüldü: minify'ın tüm projedeki kazancı **gzip'te 183 bayt**.
      *Öneri:* `<script>` dalını tamamen kaldır — regex ile doğru JS minify'ı mümkün değil.
- [x] **`View.php:423` — minify satır sonlarını silince noktalı virgülsüz JS bozuluyor**
      `let a = 1` / `let b = 2` tek satıra inip `let a=1 let b=2` oluyor, script hiç çalışmıyor.
      (Yukarıdaki düzeltme bunu da kapatıyor.)
- [x] **`View.php:421` — minify JS string'lerindeki boşlukları siliyor**
      `list.join(', ')` → `','` oluyor, kullanıcıya `a,b,c` basılıyor. (Aynı düzeltme.)
- [ ] **`View.php:401` — layout'un `<style>`'ındaki `@page` sayfayı 500'e düşürüyor**
      CSS at-rule maskesi `parse()`'ın son adımında kalkıyor ve bu `@extends` zincirindeki
      layout için de çalışıyor; layout metni child'ın directive geçişlerinden bir kez daha
      geçiyor. `ViewDirectives.php:22` tam da `page` adında bir directive kaydediyor →
      eşleşen `endif` yok → `eval()`'de ParseError.
      Child'ın kendi `<style>`'ı korunuyor; kırılan yalnızca layout.
      *Öneri:* `parse()`'a `$isExtend` bayrağı, `unmaskCssAtRules()` yalnızca en dış derlemede.
- [ ] **`View.php:691` — layout'ta tanımlı `@section` child'ınkini eziyor (ters öncelik)**
      Layout `@section('title', 'Site')` derse sayfanın kendi `@section('title', 'İletişim')`
      tanımı geçersiz kalıyor.
- [ ] **`View.php:191` — iç içe `view()` bind manifest'ini bozuyor**
      Dıştaki render'ın `usedBinds`/`compiledFiles` birikimi devralınıyor; ilk istek çağıranın
      değerini basıyor, sonrakilerde manifest yanlış yazılıyor.

## 2. Veritabanı katmanı

- [x] **`Drivers/mysql.php:166` — tek koşullu where grubu `AND (AND …)` üretip 1064 veriyor**
      Dinamik filtre kalıbı: `$c = []; if ($q) $c[] = [...]; ->where('publish',1)->where($c)`.
      Grup tek elemanlıysa ve öncesinde başka where varsa bozuk SQL. Boş grup → `()` + warning.
- [x] **`DB.php:853` — grup where'inde 4. eleman (bağlaç) hiç okunmuyor**
      `prepareWhere()` `$data[3]`'ü görmüyor; bağlaç çağrılan metottan geliyor.
      README'nin `[['views','>',50,'OR']]` örneği sessizce AND üretiyor.
- [x] **`RelationShips.php:496` — `with()` batch'lenemeyen ilişkileri iki kez çalıştırıyor**
      `belongsToMany`, `morph*`, `through` — sorguyu yarıya indirmesi gerekirken ikiye katlıyor.
- [ ] **`Drivers/sqlsrv.php:46,49` — `getLimit()` private olduğu için hiç çağrılmıyor**
      SQL Server'a MySQL `LIMIT` sözdizimi gidiyor. Erişilebilir yapılınca offset'i düşürdüğü
      ortaya çıkıyor (paginate 2. sayfadan itibaren hep 1. sayfa). İki hata birbirini gizliyor.
- [x] **`DB.php:1299` — `debugSQL()` yanlış SQL basıyor**
      Yer tutucu önek çakışması + falsy değerleri `null` basması. `name = 'veli'1` gibi.

## 3. Terminal / CLI

- [x] **`Make.php:141,168` + `Db.php:21` — `--table=` / `--dbname=` / `--db=` sessizce yok sayılıyor**
      `parseCommands` anahtarı tireleriyle saklıyor (`$parameters['--table']`), kod tiresiz
      okuyor. `db seed/backup/restore` yanlış veritabanına gidiyor.
      **Ek:** boşluk içeren bayrak değeri kesiliyor (`--title=Merhaba dunya` → `"Merhaba"`).
      *Öneri:* kodu düzelt — `['--table'] ?? ['table']`. Doküman 7 yerde `--table=` diyor.
- [x] **`Db.php:84` — `db migrate --module=X` yol birleştirmesinde `BASE_PATH."/modules/"` eksik**
      Komut "migration yok" deyip çıkıyor. Kardeş dallar (78, 88) doğru yazıyor.
- [x] **`Update.php:40` — `update rollback` / `update check` tam güncelleme çalıştırıyor**
      `begin()` alt komut dispatch'i yapmıyor. Bayraklı form (`--rollback`) doğru çalışıyor,
      ama `php terminal help` bunları alt komut olarak listeliyor.
- [x] **`Db.php:508` — `db restore` dump'ı her `;` karakterinde bölüyor**
      Tırnak takibi yok. İçinde noktalı virgül geçen metin geri yüklenmiyor, iki 1064 basılıyor,
      komut yine de "restored" diyor. Yedekten dönüş sessizce eksik.
- [x] **`MySQLBackup.php:74` — trigger/function/procedure dökümleri hiçbir dosyaya yazılmıyor**
      Çıktı dizisi kurulduktan sonra üretiliyorlar. Yedek yalnızca CREATE TABLE + INSERT içeriyor.
- [ ] **`php terminal help` 17 docblock'ta `php kernel` diyor**
      `Cache.php:17`, `Db.php:56,434,452,470`, `Make.php:95,106,118,129,157,182,192`,
      `Module.php:30`, `Release.php:29`, `Run.php:11,12`, `Security.php:19`.

## 4. Validator

- [ ] **`Required.php:14` — `type:integer` ile birlikte hiç gönderilmemiş alan doğrulamayı geçiyor**
      `['type:integer','required','min:1']` — alanı hiç göndermezsen `required` true dönüyor.
- [ ] **`Exists.php:11` — boş değer guard'ı yok, `nullable` + `exists` kırık**
      Opsiyonel alanı boş bırakınca `WHERE col = ''` koşuyor, 0 dönüyor, her zaman fail ediyor.
      `validation.md:167` "required dışında her kural boş değerde geçer" diyor — yanlış.
- [ ] **`Regex.php:25` — geçersiz UTF-8 girdi 500 üretiyor**
      `PREG_BAD_UTF8_ERROR`, hata geliştiricinin desenine yıkılıyor.
- [ ] **`Validator.php:86` — kural değeri ilk boşlukta kesiliyor**
      `date:Y-m-d H:i` → `equivalent` `'Y-m-d'` oluyor, `H` ayrı parametre. Tırnaklı form
      (`date:"Y-m-d H:i"`) çalışıyor ama hiçbir yerde yazmıyor.
- [ ] **`NotIn.php:20` — `{blocked}` listesi hesaplanıyor, hiçbir dil dosyası basmıyor**

## 5. Push notification

- [ ] **`WebPush.php:155` — SSRF guard'ı köşeli parantezli IPv6 host ile atlanıyor**
      `https://[::1]/x`, `https://[0:0:0:0:0:ffff:127.0.0.1]/x`.
- [ ] **`PushNotification.php:253` — yerel yapılandırma hatası abone hatası sayılıyor**
      `subject` boşsa her gönderim her abone için "failure"; `max_failures`'a ulaşınca
      **tüm abone tablosu siliniyor**.
- [ ] **`PushNotification.php:177` — `send()` istisna atarsa filtreler temizlenmiyor**
      Sonraki gönderim yanlış kullanıcıya gidiyor. Kuyruk işçisinde gerçekçi senaryo.
- [ ] **`service-worker.js:83,92` — `pushsubscriptionchange` CSRF nedeniyle hep 406**
      Endpoint dönünce cihaz sessizce susuyor. CSRF düzeltilse bile yeniden kayıt `app` ve
      `topics` bilgisini kaybediyor.

## 6. Yardımcılar

- [ ] **`Date.php:32` — `Date::format(time())` → `01.01.1970`**
      Parametre `?string` olduğu için `is_string()` hep true, `int` dalı ulaşılamaz.
      Epoch tutan `int` kolonu basan her yer etkileniyor.
- [ ] **`Folder.php:30` — `delete()` alt klasörlere inmiyor ama `true` dönüyor**
      Yolu ikinci kez `base_path`'ten geçiriyor. Toplu temizlik yapan job bunu başarı sayıyor.
- [ ] **`File.php:136` — `upload()` tek dosyada string, çokluda dizi dönüyor**
      Dönüş tipi girdi sayısına değil başarılı sonuç sayısına bağlı.
- [ ] **`File.php:104` — `UPLOAD_ERR_NO_FILE` kontrolü yok**
      Boş bırakılan opsiyonel dosya alanı "geçersiz dosya türü" uyarısı üretiyor.
- [ ] **`AutoSSL.php:451,457` — wildcard yenilenmiyor, tek bozuk klasör turu öldürüyor**
      Klasör adı `wildcard.example.com` olduğu için `strpos('*')` tutmuyor.
      `getDaysLeftFromBundle()`/`checkSSL()` try/catch dışında.

## 7. Zamanlama

- [ ] **`Schedule.php:150` — uzun süren görev sonrakilerin dakikasını kaçırtıyor**
      `due()` her görev için yeniden zaman alıyor. 03:00'ta başlayan 2 dakikalık yedekleme,
      aynı tetiklemedeki raporu 03:02'ye karşı değerlendirtiyor → görev o gün hiç çalışmıyor.
      *Öneri:* tick zamanını bir kez sabitle. Düzeltme iş **eksiltiyor**.
- [ ] **`Schedule.php:226,238` — cron ayrıştırma crontab'dan farklı**
      `*/n` 1-tabanlı alanlarda kayıyor (`0 3 */2 * *` → çift günler, crontab'da tek).
      Haftanın günü aralığında üst sınır 7 ise **hiçbir günle eşleşmiyor** (`5-7` hiç çalışmaz).

## 8. Hata işleyici ve log

- [ ] **`loader.php:23` — ölümcül hatada hiçbir log kanalı çalışmıyor**
      Repoda `set_error_handler` ve `register_shutdown_function` yok; `set_exception_handler`
      fatal error yakalamıyor. OOM / `max_execution_time`'da `error_logs` boş, `Log` yazmıyor,
      `error.stream` çalışmıyor. İstemciye yarım sayfa gidiyor.
- [ ] **`handle.php:1746` — `error_logs` aynı saniyedeki iki hatayı eziyor**
      Dosya adı `date('Y-m-d-H-i-s')`. Defer'de tam bu oluyor: önce exception, sonra yavaşlık
      uyarısı. Ayrıca dizin hiç temizlenmiyor.

## 9. Cache

- [ ] **`Page.php:320` — `forgetUrl()` anahtara çağıranın dilini katıyor**
      CLI'dan hiçbir kaydı silemiyor; farklı dildeki admin yalnız kendi varyantını siliyor.
- [ ] **`Page.php:204` — `serve()` `Age` başlığı basmadan `Cache-Control`'ü tekrarlıyor**
      Aşağı akıştaki cache TTL'i ikiye katlıyor.
- [ ] **`Page.php:235` — csrf tespiti `<meta name="csrf-token">` yakalamıyor**
      Framework'ün kendi JS'i o selector'ı okuyor. O token'ı taşıyan sayfa cache'lenip
      herkese aynı token gidiyor.
- [ ] **`GlobalCache.php:151` — `clear()` APCu segmentinin tamamını siliyor**
      Aynı FPM master'ını paylaşan komşu kurulumun şema cache'ini de düşürüyor.

## 10. Diğer

- [ ] **`Auth.php:196` — her `/api` isteği diske bir oturum dosyası bırakıyor**
      API modunda `getMode()` `Session::class` döndürüyor; çerez tutmayan istemci her istekte
      yeni oturum açıyor.
- [ ] **`route/web.php:29` — GET `/sign-out` CSRF'siz**
      `Route::any()` GET'i de eşliyor, `Csrf::check()` GET'te token aramıyor.
      `<img src="/sign-out">` ile oturum kapatılabiliyor.
- [ ] **`run.php:105` — `resetState()` worker'da 16 sınıfın hepsini autoload ediyor** *(kazanç)*
      `method_exists()` kullandığı için `Mail`, `cURL`, `PushNotification`, `Redis`, `Defer`,
      `Profiler` kullanılmasa bile yükleniyor.

## 12. Hata sayfasında view satır numarası *(en son — büyük iş)*

- [ ] **Hata sayfası derlenmiş view'i gösteriyor, kaynağı değil**
      Trace `View.php(NNN) : eval()'d code` ya da `storage/views/x.compiled.php` diyor.
      "Open in IDE" cache dosyasını açıyor. Hangi view'in hangi satırında patladığı
      bilinmiyor.

      **Plan — satır haritası (Twig'in yaptığının basitleştirilmişi):**

      Derleme sırasında dosya sınırlarına işaret koy:
      `<?php /*#zf:resource/views/app/main.php:1*/ ?>` — PHP yorumu, çıktıya bir şey basmıyor,
      yalnızca `@include` / `@extends` sınırlarında (satır başına değil).

      Hata anında derlenmiş dosyada geriye doğru en yakın işaret bulunur:
      `kaynak satır = işaretin kaynak satırı + (derlenmiş satır − işaretin derlenmiş satırı)`

      Bu tutar, çünkü aradaki geçişler satır sayısını koruyor: `{{ }}` → `<?= e() ?>`,
      `@if(...)` → `<?php if(...): ?>`, `@php...@endphp` — hepsi aynı satırda kalıyor.

      **Bozan üç şey ve çözümleri:**
      1. `stripComments` — çok satırlı `{{-- --}}` silinince satır sayısı düşüyor.
         Yorumu silerken içindeki satır sonlarını koru (`str_repeat("
", $n)`).
      2. `parseIncludes` / `parseExtends` — partial'ı gömüyor, section'ları taşıyor.
         Gömerken başına ve sonuna işaret koy; böylece partial'daki hata partial'ı gösterir.
      3. `minifyTemplate` — her şeyi tek satıra indiriyor, harita anlamsızlaşıyor.
         `app.debug` açıkken minify'ı atla.

      **Dokunulan yerler:** `View::stripComments`, `View::parseIncludes`, `View::parseExtends`,
      `View::render` (eval/include öncesi kaynak yolunu yayınla), `handle.php` (harita okuyucu
      + `goIDE` hedefi).

      **Maliyet:** derleme zamanı birkaç işaret satırı; çalışma zamanı dosya başına 1-2 boş
      `<?php ?>` bloğu; cache birkaç yüz bayt. Hepsi ihmal edilebilir.

      **Not:** bugüne kadarki en büyük değişiklik. Mevcut view'ların derlenmiş çıktısının
      bozulmadığı ve haritanın doğru satırı verdiği kanıtlanmadan bırakılmamalı.

## 11. Dokümanlar

- [ ] **`resource/lang/tr/validator.php` — 7 yeni kural Türkçe karaktersiz**
      `kullanilamaz`, `istenen bicimde degil`, `gecerli bir adres olmalidir`,
      `gecerli bir tarih degil`, `arasinda olmalidir`, `ayni degil`.
- [ ] **Skill düzeltmeleri**
      `api.md:353` `Queue::push(SendMail::class, …)` geçersiz — `[SendMail::class,'handle']` ·
      `recipes.md:383` `Mail::send(['body' => …])` → `message` ·
      `api.md:173` "Facades" başlığı Helpers ve kök namespace'i içine almış ·
      `api.md:381` `PushNotification` FQCN'i yok ·
      `caching.md:113` `onupdated(array $row)` — kanca argümansız çağrılıyor ·
      `SKILL.md:333` `csrf()` `void` döndürüyor ·
      `templates/views/main.php:31` `config('app.name')` yok ·
      `templates/views/pages/index.php:59` tanımsız `$.ask` ·
      `SKILL.md:176` route cache'i fallback closure da bloke ediyor ·
      `SKILL.md:193` `middleware()` artık biriktiriyor ·
      `infrastructure.md:495` `route/dynamic` her istekte çalışıyor ·
      `views.md` satır referansları kaymış · `storage/…` → `zFramework/storage/…` ·
      "README 73 KB" → 107 KB · "§20.1" → §8.3 · "no dependencies" → phpmailer + jshrink
- [ ] **README düzeltmeleri**
      Crypter anahtarı `config/crypt.php` · `Lang::get` `{name}` · `Lang::list()` klasör döner ·
      dil yolu `resource/lang/` · cron örneğinde `message` · `Route::resource` 8 route ·
      olmayan `config/redis.php`/`session.php`/`view.php` · `csrf()` void ·
      §8.1 "three things" ama 4 satır · `cache clear pages` temiz kurulumda hata ·
      `guard` açık `select()` varken devre dışı

---

## Bitenler

- [x] `fix(Str)` slug'da çift tire — `3e48aa7`
- [x] `fix(Page)` auth çerezi kontrolü ölü koddu, giriş yapmış sayfa cache'leniyordu — `2f28188`
- [x] `fix(DB)` `updateOrInsert()` ve `toggleAttach()` where'ini kaybediyordu — `6e69f96`
- [x] `docs(skill)` sorgu builder'ı tüketiyor — `eaa3e3d`
- [x] `fix(Cookie)` süre yerine an saklıyordu, worker'da giriş kırılıyordu — `05241c8`
- [x] `fix(Auth)` beni-hatırla parola izi taşımıyordu — `56b97bf`
- [x] `fix(Defer)` hatalı/yavaş job sonrakileri iptal ediyordu — `06d509a`
- [x] `fix(queue)` düşen iş işçiyi öldürüyordu — `dd86f4c`
- [x] `docs` auth çerezleri ve page-cache notları — `aa712ea`
- [x] `fix(Redis)` erişilemeyen cache her isteği 500 yapıyordu — `1a711d2`
- [x] `fix(RateLimit)` redis restart'ta dosya sayacına düşmüyordu — `305464d`
- [x] `fix(ip)` forwarded başlığa koşulsuz güveniyordu — `4f6fc1c`
- [x] `docs(config)` trusted-proxies docblock'u — `983f139`
- [x] `fix(Validator)` parametresiz kural öncekinin değerini miras alıyordu — `9970d23`
- [x] `fix(modules)` dizin adı tek yazıma indirildi, Linux'ta çözülüyor — `51beac1`
- [x] `fix(db)` tablo listesi bağlantı değişiminde tazelenmiyordu — `7af6f1b`
- [x] `fix(method)` dizi `_method` 500 yerine 400 — `876bbb3`
- [x] `fix(View)` script blokları minify edilmiyor (419/421/423) — `a03c372`
- [x] `fix(db)` restore noktali virgulde boluyordu, DELIMITER bilmiyordu — `928a33e`
- [x] `fix(MySQLBackup)` triggerlar dosyaya hic yazilmiyordu
- [x] `fix(update)` rollback/check alt komutlari tam guncelleme yapiyordu — `80fa44b`
- [x] `fix(db)` migrate --module modulun migrationlarini bulamiyordu — `d405c53`
- [x] `fix(terminal)` --table/--dbname/--db bayraklari okunmuyordu — `0559d30`
- [x] `fix(Terminal)` argv tekrar bolunuyordu, bosluklu bayrak degeri kesiliyordu
- [x] `fix(DB)` debugSQL yanlis SQL basiyordu — `fef28b4`
- [x] `fix(RelationShips)` with() batchlenemeyen iliskiyi iki kez calistiriyordu — `8b9b99a`
- [x] `fix(DB)` grup where 4. elemanı (bağlaç) okunmuyordu — `48988e3`
- [x] `fix(DB)` tek koşullu where grubu çift bağlaç üretiyordu — `d1b9ae7`
- [x] `fix(View)` minify geri geldi: src-only atlanıyor, string literaller korunuyor — `c0449c7`
      *Bilinen ve bırakılan:* `//` içeren JS regex literali hâlâ yorum sanılıyor.

## Kapatılanlar (bulgu değil)

- **`POST /` welcome terminali** — tasarım tercihi, framework'ün karşılama ekranı
- **Alerts redirect davranışı** — mevcut hâli kalsın
- **Dizi girdinin doğrulamada 500 vermesi** — tasarım tercihi, iç yapılar ayrı validator'lerle
- **`Lang::locale()` özyinelemesi** — olmayan dil yazılmaz
- **`UserObserver` parola çift kodlaması** — observer yorum satırında, ölü kod
- **`Session::flush()` yarışı** — load-once modelinin bilinçli takası
- **Eksik view adının boş 200 dönmesi** — geliştirici hatası tetikliyor, dışarıdan erişilemez
