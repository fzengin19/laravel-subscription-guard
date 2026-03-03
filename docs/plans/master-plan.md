# Laravel Subscription Guard - Master Plan

> **Versiyon**: 2.0 (Yol Haritası)
> **Tarih**: 2026-03-03
> **Durum**: Draft

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

---

## Geliştirme Fazları

### Faz 1: Core Infrastructure (4 Hafta)
**Detaylı Plan**: `phase-1-core-infrastructure/plan.md`

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

**Kapsam**:
- iyzico SDK entegrasyonu
- Ödeme akışları (Non-3DS, 3DS, CheckoutForm)
- Abonelik (recurring) işlemleri
- Kart saklama (card storage)
- Webhook handling
- Plan upgrade/downgrade

**Çıktılar**:
- IyzicoProvider sınıfı
- iyzico webhook handler
- iyzico specific DTOs
- Test suite

---

### Faz 3: PayTR Provider (3 Hafta)
**Detaylı Plan**: `phase-3-paytr-provider/plan.md`

**Kapsam**:
- PayTR iFrame API entegrasyonu
- Card storage (CAPI)
- Self-managed renewal tahsilat akışı (cron/queue)
- Dunning retry iş akışı (2/5/7 gün)
- Webhook/notification handling
- Refund API
- Marketplace/split payment (opsiyonel)

**Çıktılar**:
- PaytrProvider sınıfı
- PayTR webhook handler
- PayTR specific DTOs
- Test suite

---

### Faz 4: Licensing System (5 Hafta)
**Detaylı Plan**: `phase-4-licensing-system/plan.md`

**Kapsam**:
- License key generation (Ed25519/RSA-2048)
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

### Faz 5: Integration & Testing (4 Hafta)
**Detaylı Plan**: `phase-5-integration-testing/plan.md`

**Kapsam**:
- Frontend components (opsiyonel)
- Billing portal (opsiyonel)
- Webhook simulation command
- End-to-end tests
- Performance tests
- Security audit
- Dokümantasyon

**Çıktılar**:
- Blade/Livewire components
- Webhook simulation command
- Integration tests
- Dokümantasyon (TR/EN)
- README ve CHANGELOG

---

## Veritabanı Tabloları

| Tablo | Açıklama |
|-------|----------|
| `plans` | Plan tanımları (fiyat, özellikler, limits) |
| `licenses` | Lisans kayıtları (key, status, features, limits) |
| `license_usages` | Lisans kullanım logları |
| `subscriptions` | Abonelik kayıtları (status, billing cycle) |
| `subscription_items` | Çoklu plan desteği (multi-plan) |
| `transactions` | Ödeme işlemleri (amount, tax, status) |
| `payment_methods` | Kayıtlı kartlar (tokens, masked info) |
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

---

## Kullanıcı Deneyimi (DX)

### Frontend Components (Opsiyonel)
- Pricing Table component
- Checkout form
- Billing Portal (Faturalarım, Aboneliklerim, Kartlarım)

### Developer Tools
- `subguard:install-portal` command
- `subguard:simulate-webhook` command
- Local testing utilities

---

## Güvenlik Gereksinimleri

| Alan | Gereksinim |
|------|------------|
| **License Crypto** | Ed25519 veya RSA-2048 (PKV kullanma!) |
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
| `resources/views/` | Opsiyonel portal ve bileşen görünümleri |

---

## Bağımlılıklar

| Paket | Versiyon | Amaç |
|-------|----------|------|
| `iyzico/iyzipay-php` | ^2.x | iyzico SDK |
| `ext-sodium` | * | Ed25519 crypto |
| `laravel/framework` | ^11.0|^12.0 | Laravel |
| `spatie/laravel-package-tools` | ^1.0 | Package skeleton |

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

### v2.0 (2026-03-03)
- **YENİ YAPI**: Master plan sadece yol haritası olarak yeniden yazıldı
- Kodlar kaldırıldı, her faz için ayrı detaylı plan dosyaları oluşturulacak
- Plans klasör yapısı: her faz için plan.md, work-results.md, risk-notes.md
- 20 kritik gerçek ve çözümler eklendi
- Seat-based billing, metered billing, multi-currency eklendi

### v1.2 (2026-03-03)
- Kritik gerçeklik güncellemesi (feedback sonrası)

### v1.0 (2026-03-03)
- İlk master plan oluşturuldu
