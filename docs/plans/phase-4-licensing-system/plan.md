# Faz 4: Licensing System

> **Süre**: 5 Hafta
> **Durum**: Geliştirme Devam Ediyor (Slice-1 + Slice-2 + Slice-3 + Slice-4 + Slice-5 + Slice-6 + Slice-7 + Slice-8 tamamlandı)
**Bağımlılıklar**: Faz 1 (Core Infrastructure), Faz 2 (iyzico), Faz 3 (PayTR)

---

## Özet

Lisans key generation, validation, feature gating, subscription bridge, grace period, dunning.

---

## Hedefler

1. License key generation (Ed25519)
2. License validation (online/offline)
3. Feature gating sistemi
4. License activation/deactivation
5. Subscription → License bridge (Event-Listener)
6. Grace period ve dunning

---

## Lisans Key Generation

### Crypto Algoritmaları
- **Ed25519**: v1 için zorunlu algoritma
- **RSA-2048**: v1 kapsam dışı (v1.1 backlog)

### Key Format
- Canonical format: `SG.{BASE64URL_PAYLOAD}.{BASE64URL_SIGNATURE}`
- Payload imzalanır; signature kısaltılmaz/truncate edilmez
- İnsan dostu kısa anahtar gerekiyorsa ayrı `display_key` + checksum kullanılır
- Validation her zaman tam signature üzerinden yapılır

### PKV Uyarısı
- Partial Key Verification (PKV) kullanma!
- Güvenlik açığı var

### License Data Structure
```json
{
  "license_id": "uuid",
  "plan_id": 1,
  "user_id": 1,
  "features": ["feature1", "feature2"],
  "limits": {"api_calls": 1000, "users": 5},
  "expires_at": "2026-04-03",
  "iat": 1709507400,
  "exp": 1710112200
}
```

---

## License Validation

### Online Validation
- API endpoint'e request
- Real-time status check
- Grace period consideration
- Rate limiting

### Offline Validation
- Signature verification (public key)
- Embedded expiration (JWT-style)
- Weekly heartbeat requirement
- Revocation check (cached blacklist)
- Delta revocation sync desteği

### Validation Flow
1. License key parse
2. Signature verify (offline) veya API call (online)
3. Expiration check
4. Feature/limit check
5. Domain/activation check (if applicable)

---

## Feature Gating

### Feature Types
- **Boolean**: Açık/Kapalı
- **Limit**: Sayısal limit (5 users, 1000 API calls)
- **Usage**: Kullanım bazlı (metered)
- **Schedule**: Zaman bazlı (beta access)

### Gates
- BooleanGate: can('feature')
- LimitGate: limit('users') → 5
- UsageGate: usage('api_calls') → 850/1000
- ScheduleGate: availableUntil('beta') → timestamp

### ScheduleGate Kullanım Senaryosu
- Beta/erken erişim özelliğini belirli tarihe kadar açık tutma
- Kampanya bazlı geçici feature açma/kapama
- Trial uzatma gibi zaman bağlı yetki kuralları

### Middleware
- LicenseFeatureMiddleware: feature check
- LicenseLimitMiddleware: limit check

### Blade Directives
- @feature('feature-name')
- @featurenot('feature-name')
- @limit('limit-name')

---

## License Activation

### Activation Process
1. License key input
2. Validation (online)
3. Domain binding (v1 zorunlu)
4. Activation count check
5. Database record
6. Event fire

### Deactivation
1. License key input
2. Domain/IP unbind
3. Database update
4. Event fire

### Max Activations
- license.max_activations field
- license.current_activations counter
- Lockout after max attempts

### Activation Storage
- `license_activations` tablosu canonical source'tur
- Alanlar: domain, ip, activated_at, deactivated_at, metadata
- current_activations sayacı bu tablodan türetilen değerle tutarlı kalır

---

## Subscription → License Bridge

### Event-Listener Pattern

Referans sözleşme: `docs/plans/2026-03-04-manages-own-billing-architecture-design.md`

**Events**:
- PaymentCompleted
- PaymentFailed
- SubscriptionCreated
- SubscriptionRenewed
- SubscriptionRenewalFailed
- SubscriptionCancelled

**Listeners**:
- GenerateLicenseForSubscription
- RenewLicense
- SuspendLicense
- CancelLicense
- ExpireLicense

### Event Bağımlılık Kuralı
- Lisans domain'i provider-specific event sınıflarına doğrudan bağımlı olmayacak
- Cross-provider davranış sadece generic billing event katmanı üzerinden kurulacak
- Provider'a özel metadata ihtiyacı olursa generic event payload'ı içindeki normalize alanlar kullanılacak

### Flow
```
PaymentCompleted → SubscriptionCreated/SubscriptionRenewed
  → GenerateLicenseForSubscription 
  → License Created 
  → LicenseGenerated Event
```

### Entitlement Guard Kuralları
- `trialing` abonelikte lisans active kalır; ilk tahsilat `next_billing_date` tarihinde beklenir
- `mode=now` upgrade tahsilatı başarısızsa entitlement değişmez
- Recovery tahsilatı başarılıysa `SubscriptionRecovered` olayı ile lisans yeniden active olur

---

## Grace Period

### Status Flow
```
active → past_due → grace_period → suspended → cancelled
                 ↓
            (payment recovered)
                 ↓
               active
```

### Grace Period Settings
| Reason | Duration |
|--------|----------|
| payment_failed | 7 gün |
| card_expired | 7 gün |
| insufficient_funds | 3 gün |
| hard_decline | 0 gün |

### Implementation
- subscription.grace_ends_at field
- Daily scheduled command: subguard:suspend-overdue
- License status synced with subscription

---

## Dunning (Payment Recovery)

### Retry Strategy
- max_retries: 3
- retry_intervals: [2, 5, 7] days
- Retry on: card errors, insufficient funds
- No retry on: hard decline, fraud

### Canonical Billing Alanları
- `retry_count`
- `next_retry_at`
- `last_retry_at`
- `next_billing_date`
- `grace_ends_at`

Fazlar arasında alan adı alias'ı üretilmez; tek isimlendirme korunur.

### Recovery Rate
- Expected: 50-70% with dunning
- Without dunning: 10-20%

### Kart Güncelleme Sonrası Anında Recovery
- `PaymentMethodUpdated` olayı yayınlanır
- Abonelik `past_due` veya `grace_period` ise anında tahsilat denemesi tetiklenir
- Bu akış bir sonraki dunning cron penceresini beklemez
- Başarılıysa:
  - Subscription `active`
  - License `active`
  - `retry_count=0`, `next_retry_at=null`
- Başarısızsa normal dunning takvimi devam eder

### Komut Sorumluluk Ayrımı
- `subguard:process-dunning`: retry planlama ve deneme akışı
- `subguard:suspend-overdue`: grace süresi dolan abonelikleri suspend etme

---

## Offline Validation & Revocation

### Problem
- Offline validation'da iptal nasıl anlaşılacak?

### Solution
1. JWT-style short expiration (7 days)
2. Weekly heartbeat requirement
3. Cached revocation list
4. Grace period for missed heartbeats

### Revocation List Distribution
- Endpoint: versioned revocation feed (sequence numarası ile)
- Full sync + delta sync desteklenir
- İstemci son sequence'i saklar; sadece farkı çeker
- Heartbeat başarısızsa grace policy uygulanır, grace sonunda lisans invalid olur
- v1 formatı sabittir: hash-set + delta feed (Bloom filter v1 kapsam dışı)

### Flow
```
Software Start → Check local cache
  → If expired → API call for new signature
  → If API unreachable → Grace period (3 days)
  → If grace exceeded → License invalid
```

---

## Metered Billing

### Usage Tracking
- license_usages table
- Increment on each usage
- Reset on billing period

### Billing Process
1. Period end trigger
2. Calculate total usage
3. Apply pricing tiers
4. Charge via saved card (PaymentManager)
5. Create transaction
6. Reset usage counters

### Scheduled Command
- subguard:process-metered-billing
- Runs daily at 00:00
- Process all period-ending subscriptions

### Timezone Politikası
- Billing anchor UTC olarak saklanır
- `billing_timezone` alanı v1'de zorunludur (default: UTC)
- DST kaynaklı kaymaları önlemek için period sonu epoch tabanlı hesaplanır

---

## Seat-Based Billing

### Seat Management
- subscription_items.quantity = seat count
- incrementQuantity(): Add seat
- decrementQuantity(): Remove seat

### Seat Change Flow
1. User adds seat
2. Calculate proration (unused days)
3. Immediate charge via saved card
4. Update subscription quantity
5. Update license limits

### SeatManager Class
- addSeat(subscription, count)
- removeSeat(subscription, count)
- calculateProration(subscription, change)

---

## LicenseManager Sınıfı

### Methods
- generate(planId, userId, options): License
- validate(licenseKey, options): ValidationResult
- activate(licenseKey, domain): bool
- deactivate(licenseKey, domain): bool
- checkFeature(licenseKey, feature): bool
- checkLimit(licenseKey, limit): int
- incrementUsage(licenseKey, limit): bool
- revoke(licenseKey): bool

---

## Events

| Event | Trigger |
|-------|---------|
| LicenseGenerated | Lisans oluşturuldu |
| LicenseActivated | Lisans aktive edildi |
| LicenseDeactivated | Lisans deaktif edildi |
| LicenseExpired | Lisans süresi doldu |
| LicenseRevoked | Lisans iptal edildi |
| LicenseFeatureChecked | Feature check yapıldı |
| LicenseLimitExceeded | Limit aşıldı |

### Event Overhead Kontrolü
- `LicenseFeatureChecked` eventi v1'de kapalıdır
- v1'de config ile açma desteği yoktur (v1.1 backlog)

---

## Endpoint Protection & Audit

### Rate Limiting
- License validation endpointleri için `throttle:license-validation`
- Default policy configurable olmalı (IP + key tabanlı)

### Audit Logging
- License generate/activate/revoke/suspend aksiyonları audit trail'e yazılır
- Varsayılan Laravel logging
- `spatie/laravel-activitylog` v1 kapsam dışıdır

### Data Redaction
- Loglarda PII ve tam lisans imzası maskelenir
- Correlation ID ile payment/license akışları bağlanır

---

## Operasyonel Komutlar

- `subguard:generate-license` -> manuel lisans üretim aracı
- `subguard:check-license` -> lisans doğrulama/diagnostic aracı
- `subguard:process-dunning` -> retryable ödemelerde recovery denemeleri
- `subguard:suspend-overdue` -> grace süresi aşan abonelikleri suspend etme
- `subguard:process-metered-billing` -> dönem sonu kullanım bazlı tahsilat

---

## Çıktılar

- [ ] LicenseGenerator sınıfı
- [x] LicenseValidator sınıfı
- [x] LicenseManager sınıfı
- [x] LicenseSignature sınıfı
- [x] FeatureManager sınıfı
- [x] Feature Gates (Boolean, Limit, Usage, Schedule)
- [x] Middleware'ler
- [x] Blade directives
- [x] SeatManager sınıfı
- [x] MeteredBillingProcessor command
- [x] Event-Listener bridge
- [x] Unit tests
- [x] Integration tests

---

## Test Kriterleri

- [x] License generation çalışıyor (Ed25519)
- [x] Online validation çalışıyor
- [x] Offline validation çalışıyor
- [x] Feature gating çalışıyor
- [x] Limit check çalışıyor
- [x] Activation/deactivation çalışıyor
- [x] Subscription → License bridge çalışıyor
- [ ] Grace period çalışıyor
- [ ] Dunning çalışıyor
- [x] Seat-based billing çalışıyor
- [x] Metered billing çalışıyor

---

## Riskler ve Notlar

| Risk | Etki | Öneri |
|------|------|-------|
| Offline validation abuse | Yüksek | Short expiration + heartbeat |
| Crypto key leakage | Kritik | Private key şifreli sakla |
| Race condition (activation) | Orta | Database transaction |
| Metered billing accuracy | Orta | Comprehensive logging |

---

## Sonraki Faz

Faz 5: Integration & Testing (bu faz tamamlandıktan sonra başlayacak)
