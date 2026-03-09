# Faz 8: iyzico Live Sandbox Validation & External Test Isolation (Plan v1)

> **Durum**: Bitti
> **Hedef Başlangıç**: 2026-03-06
> **Tahmini Süre**: 3 hafta
> **Tamamlanma Tarihi**: 2026-03-09
> **Bağımlılıklar**: Faz 1-7 çıktıları, özellikle Faz 2 iyzico provider live branch'leri, Faz 5 integration test altyapısı, Faz 6 güvenlik/idempotency guardrail'leri ve Faz 7 sadeleştirme refactor'ları

---

## 1) Faz Amacı

Bu fazın amacı, paketin iyzico entegrasyonunu **gerçek sandbox API** üzerinden uçtan uca doğrulamaktır.

Ana hedefler:

- **Mock'tan Ayrışma**: Mevcut deterministic/mock test suite aynen korunurken ayrı bir live sandbox suite oluşturmak
- **Gerçek API Kanıtı**: `sync-plans`, subscription creation/cancel/reconcile, card tokenization, payment init ve uygun refund akışlarını gerçek iyzico sandbox çağrıları ile doğrulamak
- **Sistem Seviyesi Doğrulama**: Provider cevabı yanında local `Subscription`, `Transaction`, `PaymentMethod`, `WebhookCall`, event, listener ve seçili license side-effect'lerini de doğrulamak
- **Operasyonel Güvenlik**: Secrets sızdırmayan, default CI'ı bozmayan, cleanup ve forensic log üreten bir test katmanı tasarlamak
- **Gerçekçi Kapsam**: Tam otomasyon ile doğrulanabilen senaryoları, tünel/browser gerektiren operator-assisted senaryolardan ayırmak

Temel prensip: Bu faz, mevcut testleri “biraz live” hale getirmek için hack eklemez; bunun yerine **ayrı, güvenli, opt-in, dış bağımlılık farkında bir validation katmanı** kurar.

---

## 2) Faz 8 Kuralları

1. **Default Suite Untouched**: `composer test` ve mevcut `tests/Feature/*` deterministic kalır; live sandbox'a zorla bağlanmaz.
2. **Double Gate Required**: Live suite sadece açıkça `RUN_IYZICO_LIVE_SANDBOX_TESTS=true` ve `IYZICO_MOCK=false` ikilisiyle çalışır.
3. **No Secrets in Repo**: `phpunit.xml.dist` veya herhangi bir committed dosyada gerçek veya gerçekçi sandbox key/secret bulunmaz.
4. **Preflight Before Traffic**: Her live çalıştırma, credential, base URL, queue mode, callback reachability ve sandbox health check preflight'ından geçer.
5. **Run Isolation**: Her çalıştırma benzersiz `run id` kullanır; remote resource naming, local fixture naming ve forensic output buna bağlanır.
6. **Cleanup Is Mandatory**: Remote subscription/card/test artifact cleanup best-effort değil, planın zorunlu parçasıdır.
7. **Contract vs Roundtrip Separation**: Browser/tunnel gerektirmeyen contract testleri ile gerçek callback/webhook roundtrip testleri ayrı katmanlarda tutulur.
8. **Architecture Contract Preserved**: Live test eklemek için provider saflığı, provider-agnostic finalize job veya event ownership bozulmaz.
9. **Evidence First**: Başarısız her live test, request/response summary, remote reference, event id, run id ve cleanup durumunu loglar.
10. **Unsupported ≠ Hidden**: Sandbox'ın desteklemediği, yavaşlattığı veya manuel/tunnel gerektirdiği senaryolar başarısız otomasyon gibi gösterilmez; açıkça işaretlenir.

---

## 3) Faz 8 Ön-Bulguları (Planı Şekillendiren Gerçekler)

Bu faz aşağıdaki doğrulanmış gerçekler üzerine kurulacaktır:

- `tests/Feature/PhaseTwoIyzicoProviderTest.php` şu anda `beforeEach` içinde `iyzico.mock=true` ve key/secret=`null` override ettiği için gerçek sandbox çağrısı yapmaz.
- Default `phpunit.xml.dist` live suite için yeterli izolasyonu sağlamıyor; Phase 8 kapsamında mock-safe varsayımlar ve ayrı live config netleştirilmelidir.
- Mevcut test ağacında `tests/Live` veya `@group live` benzeri bir izolasyon yoktur.
- `vendor/bin/pest --list-tests` çıktısı şu anda `tests/Live/Iyzico/*` ile `tests/Unit/Support/Live/*` dosyalarını default suite içinde keşfetmektedir; skip gate güvenlik ağıdır, gerçek izolasyon sınırı değildir.
- `tests/Pest.php` tüm `tests/` ağacını `tests/TestCase.php` ile bağlamaktadır; DB connection seçimi ve migration setup bugün fiilen PHP katmanında sahiplenilmektedir.
- `IyzicoSandboxGate` mevcut halinde `SUBGUARD_LIVE_ENV_FILE` görürse `Dotenv::createMutable(...)->safeLoad()` ile live env dosyasını yükleyip `putenv`, `$_ENV`, `$_SERVER` ve Laravel config'ini mutate etmektedir; bu nedenle live helper katmanı default suite keşfi sırasında çalıştırılmamalıdır.
- `phpunit.xml.dist` normal suite için `APP_ENV`, `DB_CONNECTION`, `DB_DATABASE`, `DB_URL`, cache, queue, mail ve session varsayımlarını açıkça pinlememektedir; bugün bu davranış `tests/TestCase.php` ve ortam tesadüflerine bırakılmış durumdadır.
- `IyzicoProvider` gerçek branch'leri mevcut: `pay`, `refund`, `createSubscription`, `cancelSubscription`, `upgradeSubscription`, `listStoredCards`, `deleteStoredCard`, `validateWebhook`, `processWebhook`.
- `subguard:sync-plans --remote` ve `subguard:reconcile-iyzico-subscriptions --remote` gerçek sandbox'a çıkabilecek durumda tasarlanmıştır.
- `sync-plans` bugünkü haliyle local plan başına ayrı remote product üretmektedir; iyzico upgrade ise aynı product içindeki pricing plan'lar arasında çalışır. Bu nedenle gerçek `upgrade` testi mevcut strateji ile doğrudan güvenilir değildir.
- iyzico sandbox; test kartları, checkout form, 3DS init, subscription create/cancel, card storage, refund ve remote reconciliation için kullanılabilir; fakat callback/webhook roundtrip için publicly reachable HTTPS endpoint gerekir.

### 3.1) Doğrulanan Yapılandırma Sorunları

1. **Sorun 1 - Default suite live testleri keşfediyor**: **Doğrulandı / Kritik**. `phpunit.xml.dist` altındaki blanket `tests` directory tanımı nedeniyle normal suite live test dosyalarını yüklüyor.
2. **Sorun 2 - `testbench.yaml` build adımları PHPUnit runtime ile çakışıyor**: **Kısmen doğrulandı**. `create-sqlite-db` ve `migrate-fresh` workbench/CLI tarafında gerçek sqlite dosyası üretir; fakat resmi Testbench davranışında bu YAML, PHPUnit runtime için ana kaynak değildir. Risk runtime çakışmasından çok drift ve sahiplik belirsizliğidir.
3. **Sorun 3 - `phpunit.xml.dist` DB/runtime yapılandırması eksik**: **Doğrulandı / Yüksek**. Normal suite'in DB/cache/queue/mail/session davranışı tam olarak process-level PHPUnit config içinde pinlenmemiştir.
4. **Sorun 4 - `.gitignore` içindeki `testbench.yaml` makine drift'i yaratıyor**: **Doğrulandı / Yüksek**. Dosya şu anda ignored/local duruma düşebildiği için workbench davranışı repo dışı sapabilir.
5. **Sorun 5 - queue fallback `database` bağlantısına kayabiliyor**: **Doğrulandı / Orta**. Paket varsayılanı değiştirilmeyecek olsa da test runtime'ında `QUEUE_CONNECTION` ve `SUBGUARD_QUEUE_CONNECTION` açıkça pinlenmelidir.

### 3.2) Yapılandırma Sahipliği (Single Source of Truth)

- `phpunit.xml.dist`: Default/deterministic suite'in process-level env ve runtime-safe defaults kaynağıdır.
- `phpunit.live.xml.dist`: Live suite'in process-level env, bootstrap contract ve explicit opt-in davranışının kaynağıdır.
- `tests/TestCase.php`: Laravel `testing` connection tanımı, base schema ve package migration ownership burada kalır.
- `testbench.yaml`: PHPUnit runtime için değil, workbench/CLI davranışı için canonical dosya veya açıkça lokal-only artefakt olarak yönetilir; gri alanda bırakılmaz.
- `IyzicoSandboxGate`: Sadece live suite gate/preflight/helper katmanıdır; normal suite env loader'ı değildir.

---

## 4) Kapsam Sınırları

### Faz 8'in İçinde

- Ayrı live sandbox testsuite tasarımı
- Env/secrets hygiene ve explicit opt-in çalışma modeli
- Remote plan sync + reconcile doğrulaması
- Gerçek iyzico sandbox üzerinden provider contract testleri
- Gerçek sandbox ile tam paket orchestration testleri (`SubscriptionService`, event/listener, select local side effects)
- Operator-assisted callback/webhook roundtrip planı
- Cleanup, log, forensic ve rerun stratejisi
- Live test dökümantasyonu

### Faz 8'in Dışında

- Production/live iyzico doğrulaması
- PayTR live test fazı
- Tam browser automation ile 3DS/checkout completion zorunluluğu
- Faz 8 kapsamında zorunlu olmayan genel refactor'lar
- PhaseTwo deterministic testleri live'a çevirmek

---

## 5) Uygulama Fazları

### Phase A: Test Isolation, Runtime Ownership & Config Hygiene

#### A1: Katman sahipliğini ve test keşif sınırını netleştir

**Dosyalar**:
- `phpunit.xml.dist`
- `composer.json`
- `tests/Unit/Support/Live/` [TAŞINACAK / YENİDEN ADLANDIRILACAK]
- `tests/Live/Support/` [YENİ veya TAŞINAN]
- `tests/TestCase.php`
- `testbench.yaml`
- `.gitignore`
- `README.md`
- `docs/INSTALLATION.md`

Yapılacaklar:

- `phpunit.xml.dist` içindeki blanket `tests` keşfi kaldırılacak; default suite sadece deterministic test ağaçlarını yükleyecek.
- `tests/Unit/Support/Live/*` dosyaları audit edilip live-only bir ağaca (`tests/Live/Support/*` veya eşdeğeri) taşınacak; relocation öncesi path/reference taraması yapılacak.
- `composer test` ile `composer test-live` invocation contract'ı açıkça ayrılacak.
- `tests/TestCase.php` DB connection tanımı ve migration ownership için korunacak; PHPUnit XML içine connection array taşınmayacak.
- `testbench.yaml` için tek karar verilecek: **önerilen yol** canonical tracked workbench config'e dönmek ve `.gitignore` belirsizliğini kaldırmak. Eğer local-only kalacaksa bunun PHPUnit runtime ile ilgisi olmadığı README/INSTALLATION içinde açıkça yazılacak.
- Lokal live env dosyası repo dışında tutulacak ve `.gitignore` ile korunacak.
- README ve installation docs içinde default suite ile live suite farkı ve env precedence zinciri açıkça yazılacak.

#### A2: Normal suite runtime'ını process-level olarak pinle

**Dosyalar**:
- `phpunit.xml.dist`
- `tests/TestCase.php`
- `config/subscription-guard.php`

Yapılacaklar:

- `phpunit.xml.dist` içine normal suite için explicit runtime defaults eklenecek:
  - `APP_ENV=test`
  - `APP_MAINTENANCE_DRIVER=file`
  - `BCRYPT_ROUNDS=4`
  - `CACHE_STORE=array`
  - `MAIL_MAILER=array`
  - `SESSION_DRIVER=array`
  - `QUEUE_CONNECTION=sync`
  - `SUBGUARD_QUEUE_CONNECTION=sync`
  - `DB_URL=` (boş string ile override kapatma)
  - `DB_CONNECTION=testing`
  - `DB_DATABASE=:memory:`
  - `TELESCOPE_ENABLED=false`
  - `IYZICO_MOCK=true`
- `config/subscription-guard.php` içindeki production default'lar korunacak; test determinism package default değiştirerek değil test config'i pinleyerek sağlanacak.
- Gerekirse normal suite için küçük bir regression testi eklenip `database.default=testing`, queue=`sync`, cache=`array` beklentileri sabitlenecek.

#### A3: Ayrı live testsuite ve bootstrap contract'ını sertleştir

**Dosyalar**:
- `phpunit.live.xml.dist` [YENİ]
- `tests/Live/Iyzico/` [YENİ klasör]
- `tests/Live/Support/` [YENİ veya TAŞINAN]

Yapılacaklar:

- Mevcut suite'ten tamamen ayrı live test çalıştırma konfigürasyonu oluşturulacak.
- Live suite discovery sadece live dosyalarını kapsayacak; non-live testler bu suite'e sızmayacak.
- `composer test-live` ve `./vendor/bin/pest -c phpunit.live.xml.dist` Phase 8 için tek desteklenen live entrypoint'ler olacak.
- Live suite için özel env flag'ler tanımlanacak:
  - `RUN_IYZICO_LIVE_SANDBOX_TESTS=true`
  - `IYZICO_MOCK=false`
  - `IYZICO_API_KEY`
- `IYZICO_SECRET_KEY`
- `IYZICO_BASE_URL=https://sandbox-api.iyzipay.com`
- `IYZICO_CALLBACK_URL`
- Live suite, env eksikse fail etmek yerine **skip with reason** davranışı gösterecek.
- Gerçek credentiallar kullanıcı tarafından yönetilen lokal `.env.test` dosyasından okunacak; AI Assistant env dosyalarını okumayacak ve repo tarafında secret içeren env dosyası tutulmayacak.
- Live env convenience loading korunacaksa bu davranış sadece live bootstrap altında çalışacak; normal suite discovery sırasında çağrılmayacak.

#### A4: Live helper katmanını env sızıntısına karşı sertleştir

**Dosyalar**:
- `tests/Support/Live/IyzicoSandboxGate.php` [YENİ]
- `tests/Support/Live/IyzicoSandboxRunContext.php` [YENİ]
- `tests/Support/Live/IyzicoSandboxFixtures.php` [YENİ]
- `tests/Support/Live/IyzicoSandboxCleanupRegistry.php` [YENİ]

Sorumluluklar:

- env gate ve health check
- benzersiz `run id` üretimi
- ortak fixture üretimi (plan/user/card/customer/conversation ids)
- resmi iyzico sandbox kart matrisi (success / foreign / failure)
- remote cleanup kayıtları
- failure forensic output üretimi

Hardening kuralları:

- **Uygulanan yol**: `IyzicoSandboxGate` önce process env + PHPUnit live config değerlerini kullanır; eksik live anahtarları ise `SUBGUARD_LIVE_ENV_FILE` veya varsayılan `.env.test` içinden allowlisted olarak doldurur.
- Fallback yükleme mevcut process env değerlerini overwrite etmez; terminalden/export ile verilen değerler her zaman önceliklidir.
- Gate normal suite sırasında global env mutate eden davranışa sahip olmayacak; provider-specific config set işlemi live gate içinde, explicit koşullar altında yapılacak.
- Required vs optional live env değişkenleri ve failure/skip davranışı unit testlerle sabitlenecek.
- `SUBGUARD_LIVE_ENV_FILE` shell/IDE ortamında set olsa bile default suite live helper'lara uğramadığı için deterministic akış etkilenmeyecek.

Kart fixture kuralı:

- Sandbox kartlarında SKT ve CVV random olabilir; ancak format doğru olmalı ve SKT bugünden ileri bir tarih olmalıdır.
- Live helper katmanı aşağıdaki kartları named fixture olarak sunacaktır:
  - `success_debit_tr`: `5890040000000016` (Akbank, MasterCard, Debit)
  - `success_foreign_credit`: `5400010000000004` (Non-Turkish Credit)
  - `success_no_cancel_refund`: `5406670000000009`
  - `fail_insufficient_funds`: `4111111111111129`
  - `fail_do_not_honour`: `4129111111111111`
  - `fail_invalid_transaction`: `4128111111111112`
  - `fail_lost_card`: `4127111111111113`
  - `fail_stolen_card`: `4126111111111114`
  - `fail_expired_card`: `4125111111111115`
  - `fail_invalid_cvc2`: `4124111111111116`
  - `fail_not_permitted_to_cardholder`: `4123111111111117`
  - `fail_not_permitted_to_terminal`: `4122111111111118`
  - `fail_fraud_suspect`: `4121111111111119`
  - `fail_pickup_card`: `4120111111111110`
  - `fail_general_error`: `4130111111111118`
  - `success_mdstatus_0`: `4131111111111117`
  - `success_mdstatus_4`: `4141111111111115`
  - `fail_3ds_initialize`: `4151111111111112`

---

### Phase B: Preflight & Sandbox Fixture Provisioning

#### B1: Preflight test/health layer

**Dosya**: `tests/Live/Iyzico/PhaseEightIyzicoSandboxPreflightTest.php` [YENİ]

Zorunlu kontroller:

- key/secret boş değil ve `sandbox-` prefix'li
- `IYZICO_MOCK=false`
- base URL sandbox endpoint'i
- callback URL HTTP değil, publicly reachable HTTPS
- queue/caching stratejisi live test moduna uygun
- log/artifact path yazılabilir

Preflight yöntemi:

- callback URL için sadece string format kontrolü yapılmaz; ayrı bir health endpoint (`/subguard/live-sandbox/health` veya eşdeğeri) üzerinden gerçek `GET`/`HEAD` erişim doğrulaması yapılır
- health endpoint belirlenen timeout içinde `2xx` dönmüyorsa operator-assisted callback/webhook testleri `skip with explicit reason` olur

Başarı kriteri: preflight başarısızsa sonraki live testler aynı reason ile skip olur.

#### B2: Remote plan fixture provisioning

**Dosyalar**:
- `tests/Live/Iyzico/PhaseEightIyzicoRemotePlanSyncTest.php` [YENİ]
- Gerekirse `tests/Fixtures/phase8/iyzico-plans.php` [YENİ]

Testlenecekler:

- `subguard:sync-plans --provider=iyzico --remote` gerçek remote plan/product oluşturuyor mu
- local plan kayıtlarına remote reference yazılıyor mu
- ikinci koşuda idempotent davranıyor mu
- `--force` sadece explicit çağrıda remote refresh yapıyor mu

#### B3: Upgrade blocker doğrulaması

**KRİTİK PRECONDITION**

iyzico upgrade sadece aynı remote product içindeki pricing plan'lar arasında çalışır.

Bu nedenle Faz 8 planı iki opsiyondan birini seçmelidir:

1. **Selected**: Faz 8 içinde test fixture stratejisi eklenip iki local plan aynı remote product altında oluşturulacaktır
2. **Rejected as primary path**: Gerçek upgrade senaryosunu tamamen bloklamak sadece fallback olarak kalacaktır

Uygulama notu:

- Default `sync-plans` davranışı değiştirilmeden, live-suite fixture katmanı iki local plan için ortak remote product oluşturacak ve iki ayrı remote pricing plan reference üretecektir.
- Eğer bu fixture provisioning güvenilir şekilde kurulamazsa `upgrade` testi explicit `blocked by same-product sandbox fixture constraint` reason ile skip edilir; fake pass yazılmaz.

---

### Phase C: Automatable Live Provider Contract Tests

Bu faz browser/tunnel completion gerektirmeyen, doğrudan sandbox API ile doğrulanabilen contract senaryolarını kapsar.

#### C1: Payment init contracts

**Dosya**: `tests/Live/Iyzico/PhaseEightIyzicoPaymentContractsTest.php` [YENİ]

Senaryolar:

- `checkout_form` init → gerçek token + payment page URL
- `3ds` init → gerçek `threeDSHtmlContent` veya redirect payload
- `non_3ds` success card ile payment response
- documented fail cards ile structured failure response (`insufficient funds`, `do not honour` vb.)

Kart-senaryo eşleşmesi:

- `5890040000000016` → temel başarılı `non_3ds` contract, tokenization ve subscription create fixture kartı
- `5400010000000004` → foreign card contract doğrulaması
- `5406670000000009` → başarılı payment ama cancel/refund/post-auth kısıtlı edge contract
- `4111111111111129` → insufficient funds failure path
- `4129111111111111` → do not honour failure path
- `4128111111111112` → invalid transaction failure path
- `4127111111111113` → lost card failure path
- `4126111111111114` → stolen card failure path
- `4125111111111115` → expired card failure path
- `4124111111111116` → invalid cvc2 path
- `4123111111111117` → not permitted to card holder path
- `4122111111111118` → not permitted to terminal path
- `4121111111111119` → fraud suspect path
- `4120111111111110` → pickup card path
- `4130111111111118` → general error path
- `4131111111111117` → success but `mdStatus=0` edge case
- `4141111111111115` → success but `mdStatus=4` edge case
- `4151111111111112` → 3DS initialize failed path

Not:

- `3DS` tamamlanması bu fazda zorunlu değil; burada init contract test edilir.
- exact remote message string'e değil, response shape + success/failure contract'ına assert yazılır.
- SKT/CVV değerleri test helper tarafından valid future date + valid format ile üretilir; kart numarası dışında sabitlenmez.

#### C2: Refund contract

**Aynı dosya veya ayrı**: `tests/Live/Iyzico/PhaseEightIyzicoRefundContractTest.php` [YENİ/OPSİYONEL]

Senaryolar:

- canlı başarıyla oluşmuş ödeme üzerinden refund
- unsupported/test-card-specific refund failure'larının explicit assert'i

#### C3: Card storage contract

**Dosya**: `tests/Live/Iyzico/PhaseEightIyzicoCardVaultTest.php` [YENİ]

Senaryolar:

- card token / card user key üretimi
- `listStoredCards()`
- `deleteStoredCard()`
- cleanup sonunda orphan card kalmaması

---

### Phase D: Full Package Orchestration Tests

Bu faz provider methodunu değil, paketin gerçek orchestration katmanlarını test eder.

#### D1: Subscription creation via service

**Dosya**: `tests/Live/Iyzico/PhaseEightIyzicoSubscriptionLifecycleTest.php` [YENİ]

Senaryolar:

- `SubscriptionService` üzerinden iyzico provider-managed subscription oluşturma
- local `Subscription` kaydı oluşması
- `provider_subscription_id` dolması
- `PaymentMethod` üzerinde provider token persistence
- seçili event/listener side-effect:
  - `SubscriptionCreated`
  - gerekiyorsa linked `License` oluşumu

#### D2: Cancellation + reconcile

**Dosyalar**:
- `tests/Live/Iyzico/PhaseEightIyzicoSubscriptionLifecycleTest.php`
- `tests/Live/Iyzico/PhaseEightIyzicoReconcileTest.php` [YENİ]

Senaryolar:

- service ile cancel → remote cancel + local status
- remote state ile local state arasında fark oluştuğunda `subguard:reconcile-iyzico-subscriptions --remote` bunu kapatıyor mu
- metadata fallback yolu ile gerçek remote yolu birbirine karışmıyor mu

#### D3: Optional advanced subscription scenarios

Bu senaryolar precondition'a bağlıdır:

- gerçek same-product upgrade
- card update / payment recovery
- provider-managed recurring order success/failure sonrası remote reconcile

Bunlar Phase 8 içinde ya otomasyona alınır ya da açıkça `advanced/manual follow-up` olarak işaretlenir; gri alanda bırakılmaz.

---

### Phase E: Operator-Assisted Callback & Webhook Roundtrip

**Dosya**: `tests/Live/Iyzico/PhaseEightIyzicoWebhookRoundTripTest.php` [YENİ]

Bu faz tam otomatik CI testi değildir; publicly reachable HTTPS endpoint gerektirir.

Doğrulanacaklar:

- gerçek callback URL'ye iyzico dönüşü
- gerçek webhook delivery
- signature validation
- `WebhookCall` persistence + idempotency
- duplicate replay no-op davranışı
- `FinalizeWebhookEventJob` üzerinden provider-agnostic orchestration
- seçili local side effects:
  - `Transaction`
  - `Subscription`
  - gerekirse `License`
  - seçili notification/invoice etkileri

Önemli ayrım:

- browser/tunnel gerektiren 3DS veya checkout completion adımları operator-assisted olarak işaretlenecek
- otomatik contract testleri ile aynı suite sonucu altında “sanki aynı güvenilirlikteymiş” gibi raporlanmayacak
- operator-assisted testler explicit skip reason kullanacak:
  - `Requires public HTTPS tunnel`
  - `Requires manual browser completion`
  - `Requires sandbox webhook delivery`

---

### Phase F: Forensics, Cleanup & Documentation

#### F1: Cleanup

Her live test sonunda şu kaynaklar cleanup registry üzerinden kapatılmaya çalışılacak:

- remote subscription
- remote card
- local fixture kayıtları
- temporary forensic files (retention politikası dışında)

Cleanup politikası:

- cleanup adımı zorunludur; fakat remote tarafın transient cevabı nedeniyle cleanup failure test sonucunu otomatik olarak kırmaz
- bunun yerine test sonucu `passed with cleanup debt` veya `failed with cleanup debt` forensic notu üretir
- manual cleanup prosedürü `docs/RECIPES.md` içinde ayrı başlık olarak yazılır
- cleanup debt oluştuğunda remote reference, local fixture key ve run id zorunlu olarak kaydedilir

#### F2: Forensic output

**Dosyalar**:
- `storage/app/testing/iyzico-sandbox/` [runtime artifact]
- `docs/RECIPES.md`
- `docs/PROVIDERS.md`

Yazılacak kanıtlar:

- run id
- plan/product/pricing references
- subscription references
- payment ids / refund ids
- callback/webhook event ids
- failure summary
- cleanup summary

#### F3: Documentation

Güncellenecek dokümanlar:

- `README.md`
- `docs/INSTALLATION.md`
- `docs/PROVIDERS.md`
- `docs/RECIPES.md`

Belgelenecekler:

- default suite ile live suite farkı
- gerekli env değişkenleri
- local/tunnel çalıştırma adımları
- supported vs operator-assisted live senaryolar
- cleanup ve rerun prosedürü

---

## 6) Test Senaryosu Matrisi

### Zorunlu Otomatik Senaryolar

1. Preflight gate çalışıyor
2. Remote `sync-plans` gerçek sandbox'a çıkıyor
3. `checkout_form` init live response dönüyor
4. `3ds` init live response dönüyor
5. Başarılı veya documented failure `non_3ds` contract doğrulanıyor
6. `createSubscription()` / service-level subscription creation live çalışıyor
7. Card tokenization + `PaymentMethod` persistence doğrulanıyor
8. `cancelSubscription()` + local cancel side effects doğrulanıyor
9. `reconcile-iyzico-subscriptions --remote` gerçek remote status çekiyor
10. Live suite env yoksa deterministic skip oluyor

### Şartlı / Operator-Assisted Senaryolar

1. Checkout form completion
2. 3DS completion callback
3. Gerçek webhook delivery
4. Real same-product upgrade
5. Card update + recovery
6. Provider-managed recurring order webhook roundtrip

### Sandbox Kart Matrisi

| Kart Tipi | Kart Numarası | Beklenen Sonuç | Kullanım Yeri |
|---|---|---|---|
| Success Debit TR | `5890040000000016` | başarılı payment/subscription fixture | C1, C3, D1 |
| Foreign Credit | `5400010000000004` | foreign card contract | C1 |
| Success No Cancel/Refund | `5406670000000009` | payment success, refund/cancel/post-auth limitation | C1, C2 |
| Insufficient Funds | `4111111111111129` | deterministic failure | C1 |
| Do Not Honour | `4129111111111111` | deterministic failure | C1 |
| Invalid Transaction | `4128111111111112` | deterministic failure | C1 |
| Lost Card | `4127111111111113` | deterministic failure | C1 |
| Stolen Card | `4126111111111114` | deterministic failure | C1 |
| Expired Card | `4125111111111115` | deterministic failure | C1 |
| Invalid CVC2 | `4124111111111116` | deterministic failure | C1 |
| Not Permitted Holder | `4123111111111117` | deterministic failure | C1 |
| Not Permitted Terminal | `4122111111111118` | deterministic failure | C1 |
| Fraud Suspect | `4121111111111119` | deterministic failure | C1 |
| Pickup Card | `4120111111111110` | deterministic failure | C1 |
| General Error | `4130111111111118` | deterministic failure | C1 |
| Success mdStatus=0 | `4131111111111117` | 3DS edge case | C1, E |
| Success mdStatus=4 | `4141111111111115` | 3DS edge case | C1, E |
| 3DS Initialize Failed | `4151111111111112` | deterministic 3DS init failure | C1 |

---

## 7) Etkilenen Dosyalar Özeti

| Dosya | İşlem | Faz |
|---|---|---|
| `phpunit.xml.dist` | Default suite keşfi + runtime pinleme | A |
| `composer.json` | Normal/live suite invocation contract'ı | A |
| `phpunit.live.xml.dist` | Live testsuite + bootstrap contract | A |
| `tests/TestCase.php` | DB ownership ve regression guard | A |
| `tests/Unit/Support/Live/*` | Live-only ağaca taşınacak | A |
| `tests/Live/Support/*` | Live support helper/test ağacı | A |
| `testbench.yaml` | Workbench/CLI ownership kararı | A |
| `tests/Live/Iyzico/PhaseEightIyzicoSandboxPreflightTest.php` | YENİ | B |
| `tests/Live/Iyzico/PhaseEightIyzicoRemotePlanSyncTest.php` | YENİ | B |
| `tests/Live/Iyzico/PhaseEightIyzicoPaymentContractsTest.php` | YENİ | C |
| `tests/Live/Iyzico/PhaseEightIyzicoRefundContractTest.php` | YENİ/opsiyonel | C |
| `tests/Live/Iyzico/PhaseEightIyzicoCardVaultTest.php` | YENİ | C |
| `tests/Live/Iyzico/PhaseEightIyzicoSubscriptionLifecycleTest.php` | YENİ | D |
| `tests/Live/Iyzico/PhaseEightIyzicoReconcileTest.php` | YENİ | D |
| `tests/Live/Iyzico/PhaseEightIyzicoWebhookRoundTripTest.php` | YENİ | E |
| `tests/Support/Live/IyzicoSandboxGate.php` | YENİ | A |
| `tests/Support/Live/IyzicoSandboxRunContext.php` | YENİ | A |
| `tests/Support/Live/IyzicoSandboxFixtures.php` | YENİ | B |
| `tests/Support/Live/IyzicoSandboxCleanupRegistry.php` | YENİ | F |
| `README.md` | Live suite kullanım dokümantasyonu | F |
| `docs/INSTALLATION.md` | Canlı sandbox kurulum adımları | F |
| `docs/PROVIDERS.md` | iyzico live sandbox notları | F |
| `docs/RECIPES.md` | Tunnel/manual run recipe'leri | F |

---

## 8) Verification Plan

### Deterministic Suite (değişmeden kalmalı)

```bash
vendor/bin/pest --list-tests
composer test
composer analyse
```

Kabul kriteri:

- `vendor/bin/pest --list-tests` çıktısında `tests/Live/*` ve live-only support testleri görünmez.
- Mevcut mock/deterministic suite Phase 8 olmadan da aynı şekilde çalışmaya devam eder.
- Live env verilmediğinde hiçbir default test sandbox'a çıkmaz.
- Normal suite içinde `database.default=testing`, queue=`sync`, cache=`array` beklentileri regression seviyesinde doğrulanır.

### Live Sandbox Suite

```bash
composer test-live -- --list-tests
./vendor/bin/pest --configuration=phpunit.live.xml.dist tests/Live/Iyzico
```

Alternatif explicit çalışma:

```bash
RUN_IYZICO_LIVE_SANDBOX_TESTS=true \
IYZICO_MOCK=false \
IYZICO_API_KEY=... \
IYZICO_SECRET_KEY=... \
IYZICO_BASE_URL=https://sandbox-api.iyzipay.com \
IYZICO_CALLBACK_URL=https://<public-url>/subguard/payment/iyzico/callback \
./vendor/bin/pest --configuration=phpunit.live.xml.dist tests/Live/Iyzico
```

### Live Başarı Kriterleri

- `composer test-live -- --list-tests` sadece live test ve live support dosyalarını listeler.
- Mandatory automatable senaryoların tamamı geçer
- Unsupported/conditional senaryolar skip değilse açık reason ile raporlanır
- Forensic artifact set üretilir
- Cleanup summary raporu oluşur

---

## 9) Riskler ve Önlemler

| Risk | Etki | Olasılık | Önlem |
|---|---|---|---|
| Default suite'in yanlışlıkla live sandbox'a çıkması | Fatal | Orta | Ayrı testsuite + double gate + default mock-safe config |
| `testbench.yaml` drift'i nedeniyle workbench/CLI davranışının makineden makineye değişmesi | Yüksek | Orta | Canonical tracked dosya veya açık local-only strateji; `.gitignore` belirsizliği kaldır |
| Normal suite'in `DB_URL` / queue fallback nedeniyle beklenmeyen driver kullanması | Yüksek | Orta | `phpunit.xml.dist` içinde DB/cache/queue/mail/session explicit pinleme |
| Secrets'in repo'ya veya loglara sızması | Fatal | Orta | Committed config'ten secret çıkar, masked logging kullan |
| Localhost callback URL'nin iyzico tarafından erişilememesi | Yüksek | Yüksek | Operator-assisted tunnel recipe + preflight reachability check |
| Upgrade testinin yanlış fixture nedeniyle sahte pozitif vermesi | Yüksek | Yüksek | Same-product precondition açıkça çözülsün ya da test bloklansın |
| Sandbox flakiness/rate limit transient fail üretmesi | Orta | Orta | Retry-free contract assertion + forensic kayıt + rerun strategy |
| Orphan remote subscription/card kalması | Orta | Orta | Cleanup registry + run id naming |
| Webhook signature V3 hesabında ortam farkı | Yüksek | Düşük | Tunnel-assisted gerçek callback doğrulaması + local signature fallback testleri |
| CI secret injection olmadan live suite'in kırılması | Orta | Yüksek | Missing env -> skip, fail değil |
| Queue/notification side effect'lerinin live suite'i nondeterministic yapması | Orta | Orta | Hedefli assert + gerekli yerde fake/controlled queue strategy |

---

## 10) Kabul Metrikleri

- **L1 (Isolation)**: Default suite ile live suite kesin ayrılmış mı?
- **L2 (Reality)**: Test gerçekten sandbox API'ye çıkıyor mu, yoksa lokal fallback mi çalışıyor?
- **L3 (System Scope)**: Sadece provider response değil, local state ve seçili side-effect'ler de doğrulanıyor mu?
- **L4 (Safety)**: Secrets, cleanup ve failure forensics güvenli mi?
- **L5 (Honesty)**: Manual/tunnel gerektiren senaryolar açıkça ayrılmış mı?
- **L6 (Reusability)**: Yeni bir geliştirici dokümana bakarak aynı live suite'i yeniden çalıştırabiliyor mu?

Minimum kabul: `L1`, `L2`, `L4`, `L5` zorunlu geçer; toplam skor `>= 24/30`.

---

## 11) Faz 8 Artefaktları

- `docs/plans/phase-8-iyzico-live-sandbox-validation/plan.md`
- `docs/plans/phase-8-iyzico-live-sandbox-validation/work-results.md`
- `docs/plans/phase-8-iyzico-live-sandbox-validation/risk-notes.md`
- Güncellenmiş `docs/plans/master-plan.md`

---

## 12) Not

Bu doküman uygulanmaya hazır plan aşamasıdır. Bu adımda live sandbox test kodu yazılmaz. Faz 8'in temel kararı şudur: mevcut suite'in davranışı korunur; gerçek iyzico sandbox doğrulaması bunun üstüne ayrı bir katman olarak inşa edilir.
