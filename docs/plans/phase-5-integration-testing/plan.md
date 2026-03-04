# Faz 5: Integration & Testing

> **Süre**: 4 Hafta
> **Durum**: Planlama
> **Bağımlılıklar**: Faz 1, 2, 3, 4 (Tüm önceki fazlar)

---

## Özet

Webhook simulation, end-to-end tests, performance tests, security audit, notifications, invoice PDF/e-Fatura hook, dokümantasyon.

---

## Hedefler

1. Frontend components (v1 kapsam dışı)
2. Frontend portal kararı netleştirme (v1 kapsam dışı)
3. Webhook simulation command
4. End-to-end tests
5. Performance tests
6. Security audit
7. Dokümantasyon
8. Coupon/discount akışlarının fonksiyonel olarak tamamlanması

---

## Frontend Components (v1 Kapsam Dışı)

### Blade Components
- Pricing Table
- Checkout Form
- Payment Method Card
- Subscription Status Badge
- Invoice List
- License Key Display

### Livewire Components
- Billing Portal
- Plan Selector
- Payment Method Manager
- Subscription Manager
- Invoice Downloader

### V1 Kararı
- `subguard:install-portal` komutu v1'de uygulanmayacaktır
- Frontend portal v1.1 backlog olarak kalacaktır

### subguard:install Kapsamı
- Config publish
- Migration publish
- Route registration doğrulama
- Webhook URL ve callback URL çıktısı
- Queue/scheduler zorunlu kurulum çıktısının gösterimi

### Publishable Views
- resources/views/components/
- resources/views/livewire/
- resources/views/emails/

---

## Billing Portal

### Features
- Current subscription display
- Plan change (upgrade/downgrade)
- Payment method management
- Invoice history
- License key display
- Usage statistics

### Routes
- /billing (portal home)
- /billing/plans (plan selection)
- /billing/payment-methods (card management)
- /billing/invoices (invoice history)
- /billing/license (license display)

---

## Webhook Simulation

### Command
```bash
php artisan subguard:simulate-webhook {provider} {event}
```

### Supported Providers
- iyzico
- paytr

### Supported Events
- payment.success
- payment.failed
- subscription.created
- subscription.renewed
- subscription.cancelled
- refund.processed

### Usage
```bash
# Simulate iyzico payment success
php artisan subguard:simulate-webhook iyzico payment.success

# Simulate PayTR subscription cancelled
php artisan subguard:simulate-webhook paytr subscription.cancelled
```

### Output
- Fake payload generation
- Direct POST to webhook endpoint
- Response display

---

## End-to-End Tests

### Test Scenarios

#### Payment Flow
- [ ] Non-3DS payment success
- [ ] 3DS payment success
- [ ] CheckoutForm payment success
- [ ] Payment failure handling
- [ ] Refund flow

#### Subscription Flow
- [ ] Subscription create
- [ ] Subscription renew
- [ ] Subscription upgrade
- [ ] Subscription downgrade
- [ ] Subscription cancel
- [ ] Subscription pause/resume

#### Advanced Edge Cases (Critical)
- [ ] PayTR `mode=now` upgrade: lokal proration + anlık charge + başarı/başarısızlık doğrulaması
- [ ] Sync renewal success + async webhook: tek transaction, tek period uzatma (no double extension)
- [ ] PayTR trial: `next_billing_date = trial_ends_at`, trial bitmeden charge yok
- [ ] `PaymentMethodUpdated` sonrası `past_due` abonelikte anında recovery denemesi
- [ ] Duplicate webhook'ta no-op + 200 OK davranışı
- [ ] Retryable/non-retryable hata sınıflandırmasına göre doğru dunning akışı
- [ ] Billing timezone anchor (UTC + billing_timezone) sınır testi

#### License Flow
- [ ] License generation
- [ ] License activation
- [ ] License validation
- [ ] License revocation
- [ ] Feature gating
- [ ] Limit enforcement

#### Webhook Flow
- [ ] iyzico webhook handling
- [ ] PayTR webhook handling
- [ ] Idempotency test
- [ ] Signature verification
- [ ] Webhook endpoint verify+queue+200 prensibi
- [ ] Queue worker single-writer mutasyon prensibi

---

## Performance Tests

### Metrics
| Metric | Target |
|--------|--------|
| License validation | < 50ms (cached) |
| License validation (API) | < 200ms |
| Webhook processing | < 500ms |
| Payment processing | < 2s |

### Load Testing
- 1000 concurrent license validations
- 100 concurrent webhook deliveries
- 50 concurrent payment requests

### Optimization
- Query optimization
- Caching strategy
- Queue processing

### Compatibility Gates
- Composer constraint: `php: ^8.4`
- CI matrix: Laravel 11/12 + PHP 8.4
- `iyzico/iyzipay-php` uyumluluk smoke testleri zorunlu
- PayTR HTTP akışları için client bağımlılığı doğrulaması (guzzle)

---

## Security Audit

### Checklist
- [ ] SQL injection prevention
- [ ] XSS prevention
- [ ] CSRF protection
- [ ] Webhook signature verification
- [ ] License key encryption
- [ ] Rate limiting
- [ ] Input validation
- [ ] Output escaping
- [ ] Soft-deleted payment method/subscription ile yeniden işlem engeli

### Vulnerability Scan
- composer audit
- Static analysis (PHPStan)
- Dependency check

### Penetration Testing
- Webhook endpoint
- License validation endpoint
- Payment callback URLs

---

## Multi-Currency Support

### Exchange Rate Handling
- transactions.exchange_rate field
- transactions.provider_currency field
- Daily exchange rate update job (zorunlu)

### Currency Conversion
- Plan currency → Provider currency
- Display currency → Charge currency
- Invoice currency → Settlement currency

### Reconciliation Notu
- `exchange_rate` ve `provider_currency` finansal mutabakat raporlarında zorunlu gösterilir

---

## Notifications & Messaging

### Zorunlu Kanallar (v1)
- Mail
- Database

### v1 Dışı Kanallar
- SMS/WhatsApp v1 kapsam dışıdır

### Event -> Notification Haritası
- `PaymentFailed` -> FailedPaymentNotification
- `SubscriptionCancelled` -> SubscriptionCancelledNotification
- `LicenseRevoked` -> LicenseRevokedNotification
- `InvoicePaid` -> InvoicePaidNotification

### Uygulama Kuralları
- Notification sınıfları `ShouldQueue` kullanır
- Dispatch işlemleri `afterCommit` ile tetiklenir
- Kanal bazlı queue ayrımı (`subguard-notifications`) desteklenir

---

## E-Fatura Integration Recipe

### Events
- TransactionCompleted
- InvoicePaid
- RefundProcessed

### Webhook Integration
```php
// User's listener
class SendEfaturaOnPayment
{
    public function handle(TransactionCompleted $event)
    {
        // Call Paraşüt/BizimHesap/Uyumsoft API
        $invoice = EfaturaService::createInvoice([
            'transaction_id' => $event->transaction->id,
            'amount' => $event->transaction->amount,
            'tax_amount' => $event->transaction->tax_amount,
            'customer' => $event->transaction->payable,
        ]);
    }
}
```

### Recipe Documentation
- Paraşüt integration
- BizimHesap integration
- Uyumsoft integration
- Custom integration guide

### PDF Generation Boundary
- Core paket v1'de PDF üretimini içerir
- `spatie/laravel-pdf` v1 zorunlu bağımlılıktır
- PDF üretimi invoice domain kaydından türetilir

---

## Developer Experience (DX)

### Artisan Commands
- subguard:install
- subguard:simulate-webhook
- subguard:generate-license
- subguard:check-license

### Install Komut Ayrımı
- `subguard:install`: backend/core kurulum (config, migration, routes, scheduler hints)
- `subguard:install-portal`: v1 kapsam dışı

### Queue/Worker Notu
- Billing ve notification akışları queue-first çalıştırılır
- Horizon v1 kapsam dışıdır

### Helper Functions
- subscription() → Current subscription
- license() → Current license
- canFeature($feature) → Feature check
- getLimit($limit) → Limit value

### Facades
- PaymentManager::driver('iyzico')->pay()
- LicenseManager::validate($key)
- SubscriptionManager::create($user, $plan)

---

## Dokümantasyon

### README.md
- Proje tanıtımı
- Kurulum
- Hızlı başlangıç
- Özellikler

### INSTALLATION.md
- Gereksinimler
- Kurulum adımları
- Configuration
- Migration

### CONFIGURATION.md
- Tüm config seçenekleri
- Provider configuration
- License configuration
- Feature gates

### PROVIDERS.md
- iyzico kurulumu
- PayTR kurulumu
- Custom provider oluşturma

### LICENSING.md
- License sistemi kullanımı
- Feature gating
- Limit enforcement
- Offline validation

### RECIPES.md
- E-Fatura entegrasyonu
- Multi-currency
- Seat-based billing
- Metered billing
- Custom events
- Notifications

### API.md
- Public API reference
- Facades
- Helper functions
- Events

### CHANGELOG.md
- Versiyon geçmişi
- Breaking changes
- Deprecations

---

## CI/CD Matrix

- PHP: 8.4
- Laravel: 11.x, 12.x
- Test: Pest (unit + integration + feature)
- Workflow: GitHub Actions matrix strategy

---

## Taksitli İşlem ve Abonelik

### Problem
- iyzico/PayTR recurring API genellikle tek çekim
- Yıllık plan + 12 taksit = standart ödeme API akışı

### Kritik Ayrım
- **Bank installment (tek çekim)**: Provider tek tahsilat yapar, banka kart sahibine taksit yansıtır
- **Manual installment (çoklu çekim)**: Sistem periyodik olarak birden fazla charge üretir
- Bu iki model aynı akışta karıştırılmaz

### Solution
**Option 1**: Standart ödeme + manuel recurring
- CheckoutForm ile 12 taksit
- Saklı kart token'ı
- Cron ile aylık çekim
- İlk başarılı ödeme callback'i sonrası subscription kaydı oluşturulur
- Her periyot charge sonucu transaction + invoice kaydı açılır
- `next_billing_date` her aylık çekim sonrası +1 ay ilerler

**Option 2**: Abonelik API (tek çekim)
- Yıllık plan = tek çekim
- Taksit yok
- Subscription period başlangıcı ilk tahsilat timestamp'inden alınır
- `next_billing_date` plan periyoduna göre +1 yıl set edilir

### Bank Installment Kuralı
- Yıllık plan bankada taksitlendirilse bile sistemde tahsilat **tek çekim** kabul edilir
- Bu durumda `next_billing_date = +1 yıl` olur
- `next_billing_date` banka taksit sayısına göre bölünmez

### Recommendation
- Yıllık planlar: Tek çekim (abonelik API)
- Taksitli: Aylık plan + manuel recurring
- Native recurring + taksit kombinasyonu desteklenmiyorsa dokümantasyonda açıkça "unsupported" belirtilir

---

## Coupon & Discount Ownership

- Faz 1: sadece şema (coupons, discounts)
- Faz 5: kupon doğrulama, uygulama, transaction/invoice yansıtma, testler
- Kapsam dışı bırakılmayacak; orphan tablo bırakılmaz
- Renewal indirimi için süre modeli zorunlu: `once | forever | repeating`
- `repeating` modelinde `duration_in_months` ve `applied_cycles` takip edilir

---

## Çıktılar

- [ ] Frontend portalın v1 dışı olduğu dokümante edildi
- [ ] Webhook simulation command
- [ ] End-to-end tests
- [ ] Performance tests
- [ ] Security audit report
- [ ] Notification sınıfları ve listener haritası
- [ ] Invoice PDF üretim akışı
- [ ] Dokümantasyon (TR/EN)
- [ ] README.md
- [ ] CHANGELOG.md

---

## Test Kriterleri

- [ ] Tüm E2E tests geçiyor
- [ ] Performance hedefleri tutuyor
- [ ] Security audit temiz
- [ ] Dokümantasyon eksiksiz
- [ ] README ile kurulum çalışıyor

---

## Riskler ve Notlar

| Risk | Etki | Öneri |
|------|------|-------|
| Frontend component complexity | Düşük | v1 kapsam dışı tut |
| Multi-currency accuracy | Yüksek | Daily rate update |
| Taksitli abonelik karışıklığı | Orta | Dokümantasyonda netleştir |

---

## Proje Tamamlama

Bu faz tamamlandıktan sonra:

1. **work-results.md**: Neler yapıldı, hangi sorunlar çözüldü
2. **risk-notes.md**: Karşılaşılan riskler, çözümler, gelecek notları
3. **v1.0.0 Release**: İlk stable versiyon
4. **Packagist Publish**: composer require ile kurulabilir

---

## Sonraki Adımlar

Proje tamamlandıktan sonra:
- v1.1: Yeni provider'lar (Stripe, PayPal)
- v1.2: Gelişmiş reporting
- v2.0: Major features (teams, marketplace)
