# Laravel Subscription Guard - Master Plan

> **Versiyon**: 3.2 (Yol Haritası)
> **Tarih**: 2026-03-09
> **Durum**: Execution in Progress (Faz 1-8 tamamlandı)

---

## Proje Özeti

Laravel ekosistemi için ödeme entegrasyonu ve lisans yönetimini bir arada sunan, modüler ve genişletilebilir bir paket. Türk pazarına özel olarak iyzico ve PayTR desteği ile başlayıp, custom provider eklenebilir yapısıyla global ölçeklenebilirlik.

---

## Hedefler

### Temel Hedefler
1. **Ödeme Sistemi**: iyzico + PayTR ile tam entegre ödeme altyapısı
2. **Lisans Sistemi**: Özelleştirilebilir, modüler lisans yönetimi
3. **Genişletilebilirlik**: Custom provider desteği (ara yüz tabanlı)
4. **Kolay Entegrasyon**: Minimum konfigürasyonla çalışabilir
5. **Production-Ready**: Güvenli, test edilmiş, dokümantasyonlu
6. **Execution-Ready**: Faz 1 başlangıcında ilk commit hedefi net (migrations + interfaces)

### Kalite Hedefleri
- %90+ test coverage
- PSR-12 uyumlu kod standardı
- PHP 8.4+ type safety
- Laravel 11/12 desteği
- Kapsamlı dokümantasyon (TR/EN)

---

## Mimari Prensipler

| Prensip | Açıklama |
|---------|----------|
| **Domain-Driven Design** | Payment, Licensing, Subscription domain'leri ayrı |
| **Contract-Based Design** | Interface'ler ile tanımlama, implementasyon değiştirilebilir |
| **Event-Driven Architecture** | Ödeme, lisans, abonelik olayları için event'ler |
| **Configuration Over Convention** | Davranış config ile özelleştirilir |

## Cross-Phase Billing Architecture Contract (Zorunlu)

Detay doküman: `docs/plans/2026-03-04-manages-own-billing-architecture-design.md`

Bu sözleşme tüm fazlarda zorunludur ve ihlali faz kapatmayı bloke eder.

### Katman 1: Provider Adapter
- Provider sınıfları sadece API iletişimi, imza doğrulama ve payload/response parse yapar
- Provider sınıfları **DB state mutate etmez** (`Subscription`, `Transaction`, `PaymentMethod` vb.)
- Provider sınıfları **domain event dispatch etmez**

### Katman 2: Billing Orchestration (`SubscriptionService`)
- State mutation tek noktadan yapılır
- Idempotency, retry/grace, period update kuralları tek noktadan uygulanır
- Generic event + provider-specific event dispatch tek noktadan yapılır

### Katman 3: Event Hierarchy
- Generic eventler `src/Events/` altında provider-bağımsızdır
- Provider eventleri `src/Payment/Providers/<Provider>/Events/` altında tutulur
- Provider eventleri gerekiyorsa generic eventleri extend eder

### managesOwnBilling Kuralı
- `true` (iyzico): provider recurring charge motorunu yürütür, paket webhook sonucunu işler
- `false` (PayTR): paket recurring charge motorunu yürütür, provider sadece charge API sonucu döndürür
- Her iki yol da aynı orchestration metodlarında birleşir

### Job Agnostic Kuralı
- `FinalizeWebhookEventJob` provider-agnostic olmalıdır
- Job içinde provider'a özel domain if/else zinciri bulunamaz
- Job sadece validate + parse + service delegation yapar

---

## Pre-Implementation Architecture Gates

Faz 1 kodlamasına başlamadan önce aşağıdaki kapılar **zorunlu** olarak netleşmiş olmalıdır:

| Gate | Durum | Owner Faz | Bloklayıcı |
|------|-------|-----------|------------|
| Service/Provider sorumluluk sınırı | Tanımlı | Faz 1-2-3 | Evet |
| Billable + BillingProfile veri modeli | Tanımlı | Faz 1 | Evet |
| Webhook route + CSRF politikası | Tanımlı | Faz 1 | Evet |
| sync-plans eşleşme/CRUD stratejisi | Tanımlı | Faz 2 | Evet |
| Renewal/dunning locking ve idempotency | Tanımlı | Faz 1-3 | Evet |
| License key format + revocation distribution | Tanımlı | Faz 4 | Evet |
| Command scope (`subguard:install` dahil) | Tanımlı | Faz 1-5 | Evet |
| Logging kanal stratejisi | Tanımlı | Faz 1 | Evet |
| Timezone/billing anchor politikası | Tanımlı | Faz 4-4.1-5 | Evet |
| Coupon/discount implementation ownership | Tanımlı | Faz 5 | Evet |
| Faz 0 bootstrap (Testbench + Pest + CI iskeleti) | Tanımlı | Faz 1 başlangıcı | Evet |

---

## Faz 0 Bootstrap (Zorunlu Ön Koşul)

Faz 1 kodlamasına geçmeden önce aşağıdaki teknik temel kurulur:

- Orchestra Testbench kurulumu
- Pest test altyapısı kurulumu
- GitHub Actions matrix iskeleti (PHP 8.4, Laravel 11/12)

Bu adım tamamlanmadan migration/model implementasyonuna başlanmaz.

---

## Geliştirme Fazları

### Faz 1: Core Infrastructure (4 Hafta)
**Detaylı Plan**: `phase-1-core-infrastructure/plan.md`
**Durum**: Tamamlandı (2026-03-04)

**Kapsam**:
- Veritabanı şeması (migrations)
- Temel modeller ve ilişkiler
- Interface ve contract tanımları
- Billing davranışı ayrımı (provider-managed vs self-managed)
- Internal billing scheduler temeli (PayTR için)
- Service provider kayıtları
- Temel configuration dosyası

**Çıktılar**:
- Migration dosyaları
- Model sınıfları
- Interface tanımları
- Config dosyası

---

### Faz 2: iyzico Provider (4 Hafta)
**Detaylı Plan**: `phase-2-iyzico-provider/plan.md`
**Durum**: Tamamlandı (2026-03-04)

**Kapsam**:
- iyzico SDK entegrasyonu
- Ödeme akışları (Non-3DS, 3DS, CheckoutForm)
- Abonelik (recurring) işlemleri
- Kart saklama (card storage)
- Webhook parsing + orchestration delegation (DTO-only provider contract)
- Plan upgrade/downgrade

**Çıktılar**:
- IyzicoProvider sınıfı
- iyzico webhook handler
- iyzico specific DTOs
- Test suite

---

### Faz 3: PayTR Provider (3 Hafta)
**Detaylı Plan**: `phase-3-paytr-provider/plan.md`
**Durum**: Tamamlandı (2026-03-05)

**Kapsam**:
- PayTR iFrame API entegrasyonu
- Card storage (CAPI)
- Self-managed renewal tahsilat akışı (cron/queue)
- Dunning retry iş akışı (2/5/7 gün)
- Webhook parsing + orchestration delegation (DTO-only provider contract)
- Refund API
- Marketplace/split payment (v1 dışı, v1.1 backlog)

**Çıktılar**:
- PaytrProvider sınıfı
- PayTR webhook handler
- PayTR specific DTOs
- Test suite

---

### Faz 4: Licensing System (5 Hafta)
**Detaylı Plan**: `phase-4-licensing-system/plan.md`
**Durum**: Tamamlandı (2026-03-05)

**Kapsam**:
- License key generation (Ed25519)
- License validation (online/offline)
- Feature gating sistemi
- License activation/deactivation
- Subscription → License bridge (Event-Listener)
- Grace period ve dunning

**Çıktılar**:
- LicenseManager sınıfı
- LicenseValidator sınıfı
- Feature gating middleware
- License events ve listeners
- Test suite

---

### Faz 4.1: Implementation Closure & Hardening (2 Hafta)
**Detaylı Plan**: `phase-4-1-implementation-closure/plan.md`
**Durum**: Tamamlandi (2026-03-05)

**Kapsam**:
- Mock/placeholder kalan akışların production path kapanışı
- PayTR live endpoint boşluklarının kapatılması
- Revocation/heartbeat operasyonel hardening
- Grace period ve dunning checklist kapanışı
- Metered billing reliability ve architecture conformance gate

**Çıktılar**:
- Faz 4.1 closure checklist raporu
- Phase 5 öncesi readiness sign-off
- Güncellenmiş risk/work-results notları

---

### Faz 5: Integration & Testing (4 Hafta)
**Detaylı Plan**: `phase-5-integration-testing/plan.md`
**Durum**: Tamamlandı (2026-03-05)

**Kapsam**:
- Frontend components (v1 dışı, v1.1 backlog)
- Billing portal (v1 dışı, v1.1 backlog)
- Webhook simulation command
- End-to-end tests
- Performance tests
- Security audit
- Dokümantasyon

**Çıktılar**:
- Frontend portalın v1 dışı olduğu dokümante edildi
- Webhook simulation command
- Integration tests
- Dokümantasyon (TR/EN)
- README ve CHANGELOG

---

### Faz 6: Security Hardening & Debug Reliability (3 Hafta)
**Detaylı Plan**: `phase-6-security-hardening/plan.md`
**Durum**: Tamamlandı (2026-03-05)

**Kapsam**:
- License limit middleware TOCTOU risk kapanışı
- Model bazlı mass assignment güvenlik sertleştirmesi
- Webhook/callback idempotency fallback strateji iyileştirmesi
- Concurrency ve duplicate/retry regression test stratejisi
- Güvenlik audit raporları ile bulgu mutabakatının güncellenmesi

**Çıktılar**:
- Faz 6 güvenlik hardening plan dokümanı
- Faz 6 work-results ve risk-notes şablonları
- Güncellenmiş güvenlik kapanış kabul kriterleri

---

### Faz 7: Code Simplification & Readability Hardening (3 Hafta)
**Detaylı Plan**: `phase-7-code-simplification/plan.md`
**Durum**: Tamamlandı (2026-03-09)

**Kapsam**:
- Davranış değiştirmeden kod sadeleştirme ve okunabilirlik iyileştirmesi
- Redundant query, branch duplication ve boilerplate status transition bloklarının azaltılması
- Lock/transaction/idempotency ve guard kuralları korunarak refactor uygulanması
- Signature ve webhook akışlarında yüksek dikkatli readability refactor'ları

**Çıktılar**:
- Faz 7 planı
- Faz 7 work-results ve risk-notes şablonları
- Readability odaklı kabul kriterleri ve rollout sırası

---

### Faz 8: iyzico Live Sandbox Validation & External Test Isolation (3 Hafta)
**Detaylı Plan**: `phase-8-iyzico-live-sandbox-validation/plan.md`
**Durum**: Tamamlandı (2026-03-09)

**Kapsam**:
- Deterministic suite'i bozmadan ayrı bir iyzico live sandbox testsuite kurma
- Gerçek sandbox üzerinden payment, refund, card vault, remote plan sync ve reconcile contractlarını doğrulama
- Operator-assisted webhook/callback roundtrip akışını tam otomatik testlerden ayırma
- Secret hijyeni, run isolation, cleanup ve forensic artifact stratejisini standartlaştırma

**Çıktılar**:
- `phpunit.live.xml.dist` ile ayrık live test config'i
- `tests/Live/*` ve `tests/Support/Live/*` altında live sandbox validation katmanı
- Faz 8 work-results ve risk-notes şablonları
- Live test çalıştırma ve gizli bilgi yönetimi için netleştirilmiş dokümantasyon/config kuralları

---

## Veritabanı Tabloları

| Tablo | Açıklama |
|-------|----------|
| `plans` | Plan tanımları (fiyat, özellikler, limits) |
| `licenses` | Lisans kayıtları (key, status, features, limits) |
| `license_activations` | Domain/cihaz aktivasyon geçmişi |
| `license_usages` | Lisans kullanım logları |
| `subscriptions` | Abonelik kayıtları (status, billing cycle) |
| `subscription_items` | Çoklu plan desteği (multi-plan) |
| `transactions` | Ödeme işlemleri (amount, tax, status) |
| `invoices` | Faturalama domain kayıtları |
| `payment_methods` | Kayıtlı kartlar (tokens, masked info) |
| `billing_profiles` | Billable profil/fatura bilgileri |
| `webhook_calls` | Webhook logları (idempotency için) |
| `coupons` | Kupon tanımları |
| `discounts` | Uygulanan indirimler |
| `scheduled_plan_changes` | Plan değişiklik planlaması |

**Detaylı Şema**: Her faz planında ilgili tablolar detaylandırılacak.

---

## Kritik Gerçekler ve Çözümler

### 1. Proration Reality
- **Sorun**: iyzico/PayTR otomatik proration desteklemiyor
- **Çözüm**: Credit sistemi + scheduled plan changes

### 2. Card Storage Tokens
- **Sorun**: Recurring payments için token saklamak zorunlu
- **Çözüm**: payment_methods tablosunda provider_card_token ve provider_customer_token

### 3. PayTR iFrame Logic
- **Sorun**: PayTR direkt API değil iFrame kullanır
- **Çözüm**: PaymentResponse DTO'da iframeToken ve iframeUrl

### 4. Webhook Idempotency
- **Sorun**: Aynı webhook birden fazla kez gelebilir
- **Çözüm**: webhook_calls tablosu + deduplication logic

### 5. Grace Period vs Hard Suspend
- **Sorun**: Ödeme başarısız olduğunda ne yapılacak?
- **Çözüm**: Grace period (7 gün) + dunning + scheduled suspend

### 6. Subscription → License Bridge
- **Sorun**: Abonelik aktif olduğunda lisans nasıl oluşacak?
- **Çözüm**: Event-Listener pattern (SubscriptionActivated → GenerateLicense)

### 7. B2B Invoice Requirements
- **Sorun**: iyzico TCKN/vergi no zorunlu tutar
- **Çözüm**: Billable trait'te tax_office ve tax_id alanları

### 8. Offline License Validation
- **Sorun**: Offline validation'da iptal nasıl anlaşılacak?
- **Çözüm**: JWT-style short expiration + weekly heartbeat requirement

### 9. Seat-Based Billing
- **Sorun**: Kullanıcı sayısına göre faturalandırma
- **Çözüm**: SeatManager + incrementQuantity/decrementQuantity

### 10. Metered Billing
- **Sorun**: Kullanım bazlı fiyatlandırma
- **Çözüm**: MeteredBillingProcessor (Cron/Job)

### 11. Multi-Currency
- **Sorun**: Farklı para birimleri ve kur çevrimi
- **Çözüm**: exchange_rate ve provider_currency kolonları

### 12. E-Fatura Integration
- **Sorun**: Yasal fatura kesme zorunluluğu
- **Çözüm**: TransactionCompleted event + Recipe dokümantasyonu

### 13. Provider Billing Engine Ayrımı
- **Sorun**: iyzico ve PayTR abonelik motoru aynı değil
- **Çözüm**: `managesOwnBilling()` sözleşmesi
- **Kural**: iyzico provider-managed, PayTR self-managed

### 14. Renewal Scheduler Zorunluluğu (PayTR)
- **Sorun**: PayTR recurring için otomatik döngü motoru yok
- **Çözüm**: `subguard:process-renewals` ile `next_billing_date` bazlı tahsilat

### 15. Dunning Metadata Zorunluluğu
- **Sorun**: Başarısız ödemelerde tekrar deneme yönetimi görünmez kalır
- **Çözüm**: `retry_count` ve `next_retry_at` alanları ile retry orkestrasyonu

### 16. iyzico Plan Senkronizasyonu
- **Sorun**: PricingPlan eşlemesi olmadan abonelik oluşturulamaz
- **Çözüm**: `subguard:sync-plans` komutu ile local plan -> iyzico product/pricingPlan bağlama

### 17. PayTR Anında Upgrade (NOW)
- **Sorun**: Self-managed provider'da anında plan yükseltme nasıl yapılacak?
- **Çözüm**: Lokal proration (kalan eski plan kredisi) + fark tutarı `chargeRecurring()` ile anında tahsilat
- **Kural**: Tahsilat başarısızsa plan/entitlement değişmez

### 18. Sync Charge vs Async Webhook Race Condition
- **Sorun**: Senkron başarılı tahsilat ve ardından gelen webhook aynı işlemi ikinci kez uygulayabilir
- **Çözüm**: `provider_transaction_id` + `idempotency_key` unique ve duplicate webhook'ta no-op + 200 OK

### 19. PayTR Trial Yönetimi
- **Sorun**: PayTR native trial motoru sağlamaz
- **Çözüm**: Trial başlangıcında kart saklama, `next_billing_date = trial_ends_at`, trial bitene kadar tahsilat yok

### 20. Kart Güncelleme ile Anında Recovery
- **Sorun**: `past_due` kullanıcı kart güncellediğinde dunning penceresini beklemek gelir kaybı yaratır
- **Çözüm**: `PaymentMethodUpdated` sonrası `retryPastDuePayments()` ile anında tahsilat denemesi

### 21. sync-plans CRUD Matrisi
- **Sorun**: Plan eşleştirme stratejisi belirsiz kalırsa duplicate/bozuk mapping oluşur
- **Çözüm**: Faz 2'de create/update/deactivate/relink davranışı net matrix ile tanımlanır

### 22. License Key Format Tutarlılığı
- **Sorun**: Ed25519 tam imza ile kısa/truncated key karışırsa güvenlik zafiyeti doğar
- **Çözüm**: Signed payload format + ayrı display key/checksum yaklaşımı kullanılır

### 23. Revocation Distribution Mekanizması
- **Sorun**: Offline lisanslarda iptal bilgisinin dağıtımı belirsiz kalabilir
- **Çözüm**: Delta sync + sequence numarası + heartbeat ile revocation list dağıtımı

### 24. Renewal Locking ve Single-Writer
- **Sorun**: Scheduler paralelliğinde çift tahsilat riski
- **Çözüm**: `withoutOverlapping` + distributed lock + row lock + idempotent mutasyon

---

## Kullanıcı Deneyimi (DX)

### Frontend Components (v1 Dışı)
- Pricing Table component
- Checkout form
- Billing Portal (Faturalarım, Aboneliklerim, Kartlarım)

### Developer Tools
- `subguard:simulate-webhook` command
- Local testing utilities

---

## Güvenlik Gereksinimleri

| Alan | Gereksinim |
|------|------------|
| **License Crypto** | Ed25519 (v1 zorunlu, PKV kullanma) |
| **Webhook Signature** | HMAC-SHA256 verification |
| **Card Tokens** | Veritabanında şifreli saklama |
| **Soft Deletes** | Tüm finansal tablolarda zorunlu |
| **Rate Limiting** | License validation endpoint'lerinde |

---

## Test Stratejisi

| Test Tipi | Coverage |
|-----------|----------|
| **Unit Tests** | Her sınıf için %90+ |
| **Integration Tests** | Provider başına tüm akışlar |
| **Feature Tests** | End-to-end senaryolar |
| **Webhook Tests** | Simulate command ile local test |

### Compatibility CI Gates
- PHP 8.4 zorunlu
- Laravel 11/12 test matrisi
- `iyzico/iyzipay-php` smoke compatibility testleri
- PayTR HTTP client akış testleri (guzzle)

---

## Dokümantasyon Planı

| Doküman | İçerik |
|---------|--------|
| **README.md** | Kurulum, hızlı başlangıç |
| **INSTALLATION.md** | Detaylı kurulum |
| **CONFIGURATION.md** | Tüm config seçenekleri |
| **PROVIDERS.md** | Provider entegrasyonu |
| **LICENSING.md** | Lisans sistemi kullanımı |
| **RECIPES.md** | Yaygın senaryolar (E-Fatura, vs.) |
| **API.md** | Public API reference |
| **CHANGELOG.md** | Versiyon geçmişi |

---

## Proje Yapısı

| Dizin | Amaç |
|------|------|
| `docs/plans/master-plan.md` | Ana yol haritası (kod içermez) |
| `docs/plans/phase-*/plan.md` | Faz bazlı detaylı teknik plan |
| `docs/plans/phase-*/work-results.md` | Faz sonrası çıktı özeti |
| `docs/plans/phase-*/risk-notes.md` | Faz sonrası risk ve debt notları |
| `src/` | Paket kaynak kodu |
| `config/` | Paket konfigürasyonları |
| `database/migrations/` | Migration dosyaları |
| `tests/` | Unit/Integration/Feature testleri |
| `resources/views/` | v1 kapsam dışı (portal v1.1 backlog) |

---

## Bağımlılıklar

| Paket | Versiyon | Amaç |
|-------|----------|------|
| `iyzico/iyzipay-php` | ^2.x | iyzico SDK |
| `guzzlehttp/guzzle` | ^7.x | PayTR ve genel HTTP istemci |
| `spatie/laravel-pdf` | ^2.x | Invoice PDF üretimi (v1 zorunlu) |
| `ext-sodium` | * | Ed25519 crypto |
| `laravel/framework` | ^11.0|^12.0 | Laravel |
| `spatie/laravel-package-tools` | ^1.0 | Package skeleton |

### Development Bağımlılıkları

| Paket | Versiyon | Amaç |
|-------|----------|------|
| `orchestra/testbench` | ^9.x | Package test harness |
| `pestphp/pest` | ^2.x | Test runner |

### Kesin Entegrasyon Kararları

| Konu | Karar |
|------|-------|
| `spatie/laravel-data` | v1 DIŞI (manuel typed DTO zorunlu) |
| `spatie/laravel-pdf` | v1 İÇİ (zorunlu) |
| `spatie/laravel-activitylog` | v1 DIŞI (Laravel native audit logging kullanılacak) |
| `spatie/laravel-webhook-client` | v1 DIŞI (`webhook_calls` + custom idempotency kullanılacak) |
| `laravel/horizon` | v1 DIŞI (queue mimarisi var, Horizon dependency yok) |
| `spatie/laravel-multitenancy` | v1 DIŞI (v2 backlog) |
| Frontend portal bileşenleri | v1 DIŞI (v1.1 backlog) |
| Marketplace/split payment | v1 DIŞI (v1.1 backlog) |

---

## Referans Projeler

| Proje | Öğrenilecek Nokta |
|-------|-------------------|
| Laravel Cashier | Subscription billing pattern |
| iyzico/iyzipay-php | Official PHP SDK |
| Omnipay | Gateway abstraction pattern |
| coollabsio/laravel-saas | SaaS billing features |

---

## Değişiklik Günlüğü

### v3.1 (2026-03-05)
- Faz 6 durumu master plan üzerinde tamamlandı olarak güncellendi
- Faz 7 (Code Simplification & Readability Hardening) roadmap'e eklendi
- `phase-7-code-simplification/plan.md` oluşturuldu
- Faz 7 için zorunlu doküman seti (`work-results.md`, `risk-notes.md`) eklendi

### v3.0 (2026-03-05)
- Faz 6 (Security Hardening & Debug Reliability) roadmap'e eklendi
- `phase-6-security-hardening/plan.md` oluşturuldu
- Faz 6 için zorunlu doküman seti (`work-results.md`, `risk-notes.md`) eklendi

### v2.9 (2026-03-05)
- Faz 5 integration and testing kapanisi tamamlandi
- Security/performance/architecture audit artefaktlari eklendi
- Coupon/discount ownership kapanisi ve Faz 5 dokuman seti tamamlandi

### v2.8 (2026-03-05)
- Faz 5 uygulamasi Slice A-D seviyesinde baslatildi ve ilerleme kayitlari eklendi
- Webhook simulator, notification pipeline ve invoice PDF modulu roadmap ile hizalandi
- README + PROVIDERS + RECIPES dokumanlari Faz 5 kapsamina gore guncellendi

### v2.7 (2026-03-05)
- Faz 4.1 closure/hardening uygulamasi tamamlandi olarak isaretlendi
- Faz 3 ve Faz 4 risk notlarinda Faz 4.1 ile kapanan teknik borclar işlendi
- Faz 5 giris kapisi icin readiness durumu netlestirildi

### v2.6 (2026-03-05)
- Faz 4.1 (Implementation Closure & Hardening) Faz 4 ile Faz 5 arasına eklendi
- Faz 5 öncesi closure/hardening geçiş kapısı master roadmap'e işlendi
- Faz ownership ve timezone gate satırları 4.1 fazını kapsayacak şekilde güncellendi

### v2.5 (2026-03-05)
- Faz 3 (PayTR Provider) tamamlandı olarak işaretlendi
- `phase-3-paytr-provider/work-results.md` ve `phase-3-paytr-provider/risk-notes.md` güncellendi

### v2.4 (2026-03-04)
- Cross-phase billing architecture contract zorunlu hale getirildi
- `managesOwnBilling` davranışı 3 katmanlı sorumluluk ayrımı ile sabitlendi
- Faz 2/3 webhook ve event ownership sınırları netleştirildi

### v2.3 (2026-03-04)
- Faz 1 (Core Infrastructure) tamamlandı ve phase-1 work-results/risk-notes güncellendi
- Migration şeması create-table odaklı hale getirildi, expansion migration kaldırıldı
- Queue-first billing orchestration komut/job temeli Faz 1 kapsamına göre tamamlandı

### v2.0 (2026-03-03)
- **YENİ YAPI**: Master plan sadece yol haritası olarak yeniden yazıldı
- Kodlar kaldırıldı, her faz için ayrı detaylı plan dosyaları oluşturulacak
- Plans klasör yapısı: her faz için plan.md, work-results.md, risk-notes.md
- 24 kritik gerçek ve çözümler eklendi
- Seat-based billing, metered billing, multi-currency eklendi

### v2.1 (2026-03-04)
- Queue-first billing orchestration netleştirildi (scheduler -> queued jobs)
- Notification stratejisi (mail/database zorunlu, SMS v1 dışı) planlandı
- Invoice PDF + e-Fatura hook sınırları netleştirildi
- DTO katmanı için manuel typed DTO zorunluluğu netleştirildi
- Rate limiting + audit baseline güçlendirildi

### v2.2 (2026-03-04)
- Tüm belirsiz ifadeler kesin kapsam kararına çevrildi
- v1 içi/v1 dışı karar matrisi eklendi
- Faz kapsamları marketplace ve frontend portal için netleştirildi

### v1.2 (2026-03-03)
- Kritik gerçeklik güncellemesi (feedback sonrası)

### v1.0 (2026-03-03)
- İlk master plan oluşturuldu
