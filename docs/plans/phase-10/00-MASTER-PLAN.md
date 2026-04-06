# Phase 10 - Master Plan: Hardening & Quality

> **Amaç**: Paketi iyzico entegrasyonu için production-ready, kusursuz hale getirmek.
> **Tarih**: 2026-04-06
> **Kapsam**: Kritik buglar, yapılandırılabilirlik, test coverage boşlukları

---

## Sorun Envanteri

### Katman A - Kritik Buglar (Production Riski)

| ID | Sorun | Dosya | Satır | Seviye |
|----|-------|-------|-------|--------|
| FIX-01 | Dunning exhaustion sonrası abonelik zombi kalıyor | `src/Jobs/ProcessDunningRetryJob.php` | 54-55 | CRITICAL |
| FIX-02 | Cache düşerse tüm lisanslar geçerli sayılıyor (fail-open) | `src/Licensing/LicenseRevocationStore.php` + `config/subscription-guard.php` | 79, 85 | CRITICAL |
| FIX-03 | Metered billing'de usage charge onaylanmadan siliniyor | `src/Billing/MeteredBillingProcessor.php` | 150-152 | CRITICAL |

### Katman B - Yapılandırılabilirlik ve Sağlamlık

| ID | Sorun | Dosya | Satır | Seviye |
|----|-------|-------|-------|--------|
| FIX-04 | Grace period, max retry, retry interval hardcoded | `src/Subscription/SubscriptionService.php`, `src/Jobs/ProcessDunningRetryJob.php` | 192, 372, 422 | HIGH |
| FIX-05 | addMonth() ay sonu kayması (31 Oca → 28 Şub → 28 Mar) | `src/Subscription/SubscriptionService.php` | 392-394, 549-555 | HIGH |
| FIX-06 | Subscription state machine doğrulaması yok | `src/Enums/SubscriptionStatus.php` | tüm dosya | HIGH |
| FIX-07 | Webhook/callback lock race condition ve validation eksikleri | `src/Http/Controllers/WebhookController.php`, `PaymentCallbackController.php` | 36-39, 57-60 | MEDIUM |
| FIX-08 | PHPStan 34 false-positive temizlenmemiş | `config/subscription-guard.php`, `phpstan.neon.dist` | tüm config | MEDIUM |

### Katman C - Test Coverage Boşlukları

| ID | Sorun | Hedef Dosya | Seviye |
|----|-------|-------------|--------|
| TEST-01 | SubscriptionService 6 public methodu test edilmemiş | `tests/Feature/` | HIGH |
| TEST-02 | iyzico webhook signature 3 pattern test edilmemiş | `tests/Feature/` veya `tests/Unit/` | HIGH |
| TEST-03 | PaymentCallbackController (3DS/checkout) minimal test | `tests/Feature/` | HIGH |
| TEST-04 | Refund end-to-end flow test yok | `tests/Feature/` | MEDIUM |
| TEST-05 | Dunning exhaustion senaryosu test yok | `tests/Feature/` | HIGH |
| TEST-06 | LicenseManager::revoke() hiç test edilmemiş | `tests/Feature/` veya `tests/Unit/` | MEDIUM |
| TEST-07 | Race condition / concurrent operation testleri sıfır | `tests/Feature/` | MEDIUM |

---

## Uygulama Sırası

Bağımlılıklara göre sıralama:

```
Aşama 1 (Bağımsız - Paralel çalışılabilir):
  FIX-01  Dunning exhaustion → suspension pipeline
  FIX-02  License revocation fail-closed default
  FIX-03  Metered billing usage silme güvenliği

Aşama 2 (FIX-01'e bağlı):
  FIX-04  Hardcoded değerleri config'e taşıma
          (FIX-01'deki max_retry değeri config'den okunacak)

Aşama 3 (Bağımsız - Paralel çalışılabilir):
  FIX-05  addMonth() ay sonu kayması düzeltmesi
  FIX-06  Subscription state machine doğrulaması
  FIX-07  Webhook/callback lock iyileştirmeleri
  FIX-08  PHPStan false-positive temizliği

Aşama 4 (Tüm FIX'ler tamamlandıktan sonra):
  TEST-01  SubscriptionService method testleri
  TEST-02  iyzico webhook signature testleri
  TEST-03  PaymentCallbackController testleri
  TEST-04  Refund end-to-end testleri
  TEST-05  Dunning exhaustion testleri (FIX-01 sonrası)
  TEST-06  LicenseManager::revoke() testleri
  TEST-07  Race condition testleri
```

---

## Her Sorunun Detaylı Planı

Her sorun için ayrı bir dosya bu dizinde bulunur:

- [FIX-01-dunning-exhaustion.md](./FIX-01-dunning-exhaustion.md)
- [FIX-02-license-revocation-failopen.md](./FIX-02-license-revocation-failopen.md)
- [FIX-03-metered-billing-usage-safety.md](./FIX-03-metered-billing-usage-safety.md)
- [FIX-04-hardcoded-values-to-config.md](./FIX-04-hardcoded-values-to-config.md)
- [FIX-05-addmonth-drift.md](./FIX-05-addmonth-drift.md)
- [FIX-06-subscription-state-machine.md](./FIX-06-subscription-state-machine.md)
- [FIX-07-webhook-lock-hardening.md](./FIX-07-webhook-lock-hardening.md)
- [FIX-08-phpstan-cleanup.md](./FIX-08-phpstan-cleanup.md)
- [TEST-01-subscription-service-methods.md](./TEST-01-subscription-service-methods.md)
- [TEST-02-iyzico-webhook-signature.md](./TEST-02-iyzico-webhook-signature.md)
- [TEST-03-payment-callback-controller.md](./TEST-03-payment-callback-controller.md)
- [TEST-04-refund-e2e.md](./TEST-04-refund-e2e.md)
- [TEST-05-dunning-exhaustion.md](./TEST-05-dunning-exhaustion.md)
- [TEST-06-license-revoke.md](./TEST-06-license-revoke.md)
- [TEST-07-race-conditions.md](./TEST-07-race-conditions.md)

---

## Başarı Kriterleri

Phase 10 tamamlandığında:

1. `composer test` - Tüm mevcut + yeni testler geçiyor
2. `composer analyse` - PHPStan sıfır hata (veya yalnızca baseline'd bilinen sorunlar)
3. Dunning tükenmesi sonrası abonelik otomatik suspend ediliyor
4. Cache kaybında lisanslar fail-closed davranıyor
5. Metered billing'de usage kaybı riski yok
6. Grace period, retry sayısı, retry aralığı config'den okunuyor
7. Ay sonu kayması düzeltilmiş
8. Geçersiz state geçişleri engellenmiş
9. SubscriptionService, webhook signature, callback, refund, revoke, race condition testleri mevcut
