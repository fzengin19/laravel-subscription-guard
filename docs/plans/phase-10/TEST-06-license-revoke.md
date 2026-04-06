# TEST-06: LicenseManager::revoke() ve Edge Case Testleri

## Problem

`src/Licensing/LicenseManager.php`'deki `revoke()` method'u hiç test edilmemiş. Ayrıca `activate()` ve `deactivate()` method'larının edge case'leri eksik.

Test edilmemiş alanlar:
- `revoke()` - lisans iptali
- `activate()` - max_activations = 0, unicode domain, duplicate domain
- `deactivate()` - olmayan domain, count drift
- `checkFeature()` - feature_overrides etkisi
- `checkLimit()` - limit_overrides etkisi, boundary değerler

## Test Dosyası

Yeni dosya: `tests/Feature/PhaseTenLicenseManagerTest.php`

## Test Senaryoları

### revoke() testleri

```
it revokes an active license
```
- Active license oluştur
- `LicenseManager::revoke($licenseId)` çağır
- License status = revoked/suspended doğrula (implementasyona bağlı)
- `LicenseRevocationStore`'a eklendiğini doğrula

```
it prevents usage of revoked license
```
- License oluştur ve revoke et
- `LicenseManager::validate($licenseKey)` çağır
- `ValidationResult.valid = false` doğrula
- Failure reason'da revocation belirtilmeli

```
it handles revoking already revoked license idempotently
```
- License revoke et
- Tekrar revoke et
- Exception fırlatmadan başarılı tamamlanmalı

```
it adds license to revocation store on revoke
```
- Revoke öncesi `LicenseRevocationStore::isRevoked($id)` → false
- Revoke sonrası → true

### activate() edge case testleri

```
it rejects activation when max_activations is zero
```
- License: max_activations = 0
- `activate($licenseId, 'example.com')` çağır
- Başarısız olmalı (aktivasyon limiti 0 = aktivasyon kapalı)

```
it handles unicode domain names in activation
```
- `activate($licenseId, 'türkçe-domain.com.tr')` çağır
- Başarılı olmalı
- `license_activations` tablosunda domain doğru kaydedilmeli

```
it rejects duplicate activation for same domain
```
- `activate($licenseId, 'example.com')` → başarılı
- `activate($licenseId, 'example.com')` tekrar → başarısız veya idempotent
- `current_activations` = 1 (2 değil)

```
it enforces max_activations limit
```
- License: max_activations = 2
- activate('domain1.com') → başarılı, current_activations = 1
- activate('domain2.com') → başarılı, current_activations = 2
- activate('domain3.com') → başarısız, current_activations = 2

```
it increments current_activations correctly
```
- Her başarılı activation'da `current_activations` +1 doğrula
- DB'deki değer ile model attribute tutarlı

### deactivate() edge case testleri

```
it deactivates an active domain
```
- License aktif et (domain1.com)
- `deactivate($licenseId, 'domain1.com')` çağır
- `license_activations` tablosunda `deactivated_at` set doğrula
- `current_activations` -1 doğrula

```
it handles deactivation of non-existent domain gracefully
```
- `deactivate($licenseId, 'nonexistent.com')` çağır
- Exception fırlatmadan false döndürmeli veya no-op olmalı
- `current_activations` değişmemeli

```
it reconciles current_activations count on deactivation
```
- Eğer `current_activations` DB'de yanlışlıkla 5 ama aktif activation sayısı 3 ise
- deactivate yapıldığında count düzeltilmeli (veya en azından negatife düşmemeli)

### checkFeature() edge case testleri

```
it returns true when feature is in license features list
```
- License: features = ['api_access', 'export']
- `checkFeature($licenseId, 'api_access')` → true

```
it returns false when feature is not in list
```
- `checkFeature($licenseId, 'admin_panel')` → false

```
it respects feature_overrides on license model
```
- License: features = ['api_access'], feature_overrides = ['admin_panel' => true]
- `checkFeature($licenseId, 'admin_panel')` → true (override)

```
it allows feature_overrides to disable a plan feature
```
- License: features = ['api_access'], feature_overrides = ['api_access' => false]
- `checkFeature($licenseId, 'api_access')` → false (override)

### checkLimit() edge case testleri

```
it returns correct limit from license limits
```
- License: limits = ['api_calls' => 1000, 'storage_mb' => 500]
- `checkLimit($licenseId, 'api_calls')` → 1000

```
it returns null for undefined limit
```
- `checkLimit($licenseId, 'nonexistent_limit')` → null

```
it respects limit_overrides on license model
```
- License: limits = ['api_calls' => 1000], limit_overrides = ['api_calls' => 5000]
- `checkLimit($licenseId, 'api_calls')` → 5000 (override)

```
it returns zero limit correctly (not null)
```
- License: limits = ['api_calls' => 0]
- `checkLimit($licenseId, 'api_calls')` → 0 (limit kapalı, null değil)

### validate() ile revocation etkileşimi

```
it fails validation for revoked license even if not expired
```
- License oluştur (expires_at = gelecek)
- Revoke et
- validate() → invalid, reason = revoked

```
it passes validation for non-revoked active license
```
- License oluştur (expires_at = gelecek)
- validate() → valid

## Test Altyapısı

```php
use SubscriptionGuard\LaravelSubscriptionGuard\Contracts\LicenseManagerInterface;

beforeEach(function () {
    // Ed25519 test key pair (mevcut test altyapısından)
    config()->set('subscription-guard.license.keys.private', $testPrivateKey);
    config()->set('subscription-guard.license.keys.public', $testPublicKey);
});

$licenseManager = app(LicenseManagerInterface::class);
```

## Doğrulama

1. revoke() lisansı iptal ediyor ve revocation store'a ekliyor
2. Revoked lisans validate() geçemiyor
3. activate() max_activations, duplicate domain, unicode domain senaryolarını doğru yönetiyor
4. deactivate() graceful handling (olmayan domain, count drift)
5. Feature/limit overrides doğru çalışıyor
6. `composer test` → tüm testler geçiyor
