# Faz 8: iyzico Live Sandbox Validation - Risk Notes

> **Durum**: Tamamlandı
> **Tamamlanma Tarihi**: 2026-03-09
> **Son Güncelleme**: 2026-03-09

## Karşılaşılan Riskler

### 1. Default suite live testleri keşfediyordu

- Blanket `tests` suite tanımı nedeniyle `composer test` altında live integration ve live helper testleri yükleniyordu.
- Skip gate koruması vardı, fakat bu gerçek izolasyon sağlamıyordu ve env/config mutasyonu riski taşıyordu.

### 2. Mutable dotenv yükleme normal suite'e sızabilirdi

- `IyzicoSandboxGate` içindeki eski mutable helper env loading, `SUBGUARD_LIVE_ENV_FILE` shell/IDE ortamında set olduğunda live config'in süreç env'ine taşınmasına yol açabilirdi.

### 3. `testbench.yaml` YAML biçimi static analysis bootstrap'ını bozuyordu

- `DB_DATABASE=:memory:` girdisi YAML tarafından nested array olarak parse edildiği için Testbench `LoadEnvironmentVariablesFromArray` bootstrap'ında `Array to string conversion` oluşuyordu.

### 4. Workbench config drift riski

- `testbench.yaml` ignore altında olduğu için makineden makineye farklı içeriklerle kullanılma riski vardı.

### 5. Live suite halen operator-assisted bileşenler içeriyor

- Webhook/callback roundtrip senaryoları public HTTPS tunnel ve manuel operatör adımı gerektiriyor.

### 6. DX fallback gerçek live-suite entegrasyon hatalarını görünür hale getirdi

- `.env.test` fallback geri geldikten sonra `composer test-live` artık skip yerine gerçek sandbox çağrılarını çalıştırıyor.
- Bu koşuda 5 senaryo fail verdi: refund success, remote plan sync exit code, subscription lifecycle, card vault ve reconcile.
- Oracle değerlendirmesine göre bu hatalar fallback değişikliğinden değil, önceden maskelenmiş live-suite/provider-sandbox entegrasyon sorunlarından kaynaklanıyor.

## Çözüm Yaklaşımları

- Default suite `tests/Feature` + `tests/Unit` + top-level test dosyaları ile sınırlandı.
- Live-only helper testleri `tests/Live/Support/*` altına taşındı ve live suite `tests/Live` altına genişletildi.
- `IyzicoSandboxGate` içindeki helper-managed mutable dotenv yükleme kaldırıldı; yerine process env öncelikli, allowlisted ve non-overwriting fallback getirildi.
- `phpunit.xml.dist` deterministic runtime sahipliğini açıkça üstlenecek şekilde DB/cache/queue/mail/session varsayılanları eklendi.
- `testbench.yaml` içindeki `:memory:` girdisi quote edilerek bootstrap crash'i giderildi.
- `testbench.yaml` ignore kuralı kaldırılarak drift görünür hale getirildi.
- Dokümantasyon process-env öncelikli ve fallback destekli sözleşmeye çevrildi.
- Kalan 5 live fail, DX fallback tamamlandıktan sonra ayrı live-suite debt olarak sınıflandırıldı; bu değişiklik kapsamında gate davranışıyla karıştırılmadı.

## Gelecek Notları

- Gerçek sandbox credential'ları kullanıcı tarafından yönetilir; exported process env her zaman önceliklidir, `.env.test` ise yalnız eksik live anahtarları tamamlayan opsiyonel fallback olarak kullanılır. Assistant env dosyalarını okumaz.
- Operator-assisted webhook roundtrip senaryoları ayrı kalmalıdır; deterministic suite'e alınmamalıdır.
- `composer test-live` çıktısındaki skip reason'lar, credential/tunnel ön koşullarını doğru şekilde operatöre göstermeye devam etmelidir.

## Technical Debt

- `composer analyse` artık bootstrap aşamasını geçiyor; ancak `config/subscription-guard.php` üzerindeki `larastan.noEnvCallsOutsideOfConfig` bulguları ve `src/Concerns/Billable.php` unused trait uyarısı Phase 8 scope dışındaki mevcut static-analysis debt olarak duruyor.
- `testbench.yaml` şu anda working tree'de görünür hale getirildi; sonraki commit'te versioned/canonical workbench config olarak dahil edilmelidir.
- Live suite'in gerçek remote execution sonucu, kullanıcı ortamındaki credential ve callback erişilebilirliğine bağlıdır; bu Phase 8 içinde güvenli skip davranışıyla kapsandı, fakat her makinede gerçek sandbox kanıtı üretmek kullanıcı ortamına bağlı kalır.
- Bilinen post-fallback live-suite debt: `PhaseEightIyzicoRefundContractTest`, `PhaseEightIyzicoRemotePlanSyncTest`, `PhaseEightIyzicoSubscriptionLifecycleTest`, `PhaseEightIyzicoCardVaultTest`, `PhaseEightIyzicoReconcileTest`.
