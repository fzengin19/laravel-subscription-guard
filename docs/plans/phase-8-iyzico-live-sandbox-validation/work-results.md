# Faz 8: iyzico Live Sandbox Validation - Work Results

> **Durum**: Tamamlandı
> **Tamamlanma Tarihi**: 2026-03-09
> **Son Güncelleme**: 2026-03-09

## Yapılanlar

- Default test suite ile live suite arasındaki keşif sınırı netleştirildi.
- `phpunit.xml.dist` deterministic suite için `tests/Feature`, `tests/Unit`, `tests/ArchTest.php` ve `tests/ExampleTest.php` ile sınırlandı.
- `phpunit.xml.dist` içine `APP_ENV`, `DB_URL`, `DB_CONNECTION`, `DB_DATABASE`, cache, queue, mail, session ve telescope varsayılanları eklendi.
- `phpunit.live.xml.dist` live suite için `tests/Live` ağacını kapsayacak şekilde genişletildi ve `SUBGUARD_LIVE_ENV_FILE=.env.test` fallback wiring'i geri eklendi.
- Live-only helper testleri `tests/Unit/Support/Live/*` altından `tests/Live/Support/*` altına taşındı.
- `IyzicoSandboxGate` içindeki mutable dotenv yükleme akışı kaldırıldı; gate artık process env önceliği ile çalışıyor ve eksik live anahtarları allowlisted `.env.test` fallback'inden tamamlıyor.
- `testbench.yaml` içindeki `DB_DATABASE=:memory:` girdisi quote edilerek Testbench bootstrap hatası giderildi.
- `.gitignore` içinden `testbench.yaml` ignore kuralı çıkarıldı ve workbench config drift'i görünür hale getirildi.
- `README.md`, `docs/INSTALLATION.md`, `docs/PROVIDERS.md` ve `docs/RECIPES.md` live suite sözleşmesiyle hizalandı.
- Faz 8 planı, work-results ve risk-notes dosyaları gerçek uygulama durumu ile senkronize edildi.

## Oluşturulan / Güncellenen Dosyalar

### Yeni Dosyalar

- `tests/Feature/PhaseEightTestRuntimeIsolationTest.php`
- `tests/Live/Support/IyzicoSandboxGateTest.php`
- `tests/Live/Support/IyzicoSandboxFixturesTest.php`
- `tests/Live/Support/IyzicoSandboxRunContextTest.php`
- `tests/Live/Support/IyzicoSandboxCleanupRegistryTest.php`

### Güncellenen Dosyalar

- `.gitignore`
- `README.md`
- `docs/INSTALLATION.md`
- `docs/PROVIDERS.md`
- `docs/RECIPES.md`
- `docs/plans/master-plan.md`
- `docs/plans/phase-8-iyzico-live-sandbox-validation/plan.md`
- `phpunit.xml.dist`
- `phpunit.live.xml.dist`
- `testbench.yaml`
- `tests/Support/Live/IyzicoSandboxGate.php`

### Taşınan / Kaldırılan Dosyalar

- `tests/Unit/Support/Live/IyzicoSandboxGateTest.php` -> `tests/Live/Support/IyzicoSandboxGateTest.php`
- `tests/Unit/Support/Live/IyzicoSandboxFixturesTest.php` -> `tests/Live/Support/IyzicoSandboxFixturesTest.php`
- `tests/Unit/Support/Live/IyzicoSandboxRunContextTest.php` -> `tests/Live/Support/IyzicoSandboxRunContextTest.php`
- `tests/Unit/Support/Live/IyzicoSandboxCleanupRegistryTest.php` -> `tests/Live/Support/IyzicoSandboxCleanupRegistryTest.php`

## Çözülen Sorunlar

- Default suite'in `tests/Live/*` ve live-only helper testlerini keşfetmesi engellendi.
- Normal test runtime'ının DB/cache/queue/mail/session davranışı process-level PHPUnit config içine alındı.
- `IyzicoSandboxGate` üzerinden normal test keşfi sırasında kontrolsüz live env yükleme riski kaldırıldı; controlled fallback yalnız eksik live anahtarlarını dolduruyor.
- `testbench.yaml` içindeki YAML parse hatası nedeniyle `composer analyse` bootstrap crash'i giderildi.
- Live suite dokümantasyonu process-env öncelikli ve `.env.test` fallback destekli sözleşmeye geçirildi.

## Test Sonuçları

- `vendor/bin/pest tests/Feature/PhaseEightTestRuntimeIsolationTest.php tests/Live/Support` -> **PASS** (`21 tests`, `100 assertions`)
- `vendor/bin/pest tests/Feature/PhaseEightTestRuntimeIsolationTest.php tests/Live/Support/IyzicoSandboxGateTest.php` -> **PASS** (`14 tests`, `84 assertions`) after DX fallback update
- `vendor/bin/pest --list-tests` -> **PASS** (default suite `tests/Live/*` keşfetmiyor)
- `vendor/bin/pest -c phpunit.live.xml.dist --list-tests` -> **PASS** (yalnız live suite testleri listeleniyor)
- `composer test` -> **PASS** (`129 tests`, `562 assertions`)
- `composer test-live` -> **PARTIAL** (`.env.test` fallback ile gerçek sandbox execution başladı; 5 integration senaryosu fail, 3 operator-assisted senaryo skip, kalan live support ve payment-contract senaryoları pass)
- `composer analyse` -> **BOOTSTRAP PASS, ANALYSIS DEBT REMAINS** (Testbench bootstrap crash giderildi; kalan `34` Larastan bulgusu repo çapında pre-existing/static-analysis debt olarak not edildi)
