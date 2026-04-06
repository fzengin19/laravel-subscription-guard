# FIX-02: License Revocation Fail-Open Güvenlik Açığı

## Problem

`src/Licensing/LicenseRevocationStore.php` satır 79'da, revocation cache süresi dolduğunda:

```php
return ! (bool) config('subscription-guard.license.revocation.fail_open_on_expired', true);
```

`fail_open_on_expired` default olarak `true`. Bu durumda `!true = false` döner, yani lisans "revoked değil" olarak kabul edilir. Cache backend'i çökerse veya TTL dolduğunda **tüm iptal edilmiş lisanslar geçerli sayılır**.

`config/subscription-guard.php` satır 85:
```php
'fail_open_on_expired' => (bool) env('SUBGUARD_LICENSE_REVOCATION_FAIL_OPEN_ON_EXPIRED', true),
```

Ek sorun: `LicenseRevocationStore.php` satır 112-115'te lock/block timeout uyumsuzluğu:
```php
$lock = cache()->lock($this->cacheKey().':lock', 5);  // lock 5 saniye
return (bool) $lock->block(3, $callback);               // block 3 saniye bekle
```

Lock 5 saniye boyunca tutuluyor ama `block()` 3 saniyede timeout alıp false dönebilir. Bu durumda lock hala tutuluyor olabilir.

## Etkilenen Dosyalar

| Dosya | Satır | Sorun |
|-------|-------|-------|
| `config/subscription-guard.php` | 85 | `fail_open_on_expired` default `true` |
| `src/Licensing/LicenseRevocationStore.php` | 79 | Cache expired → lisanslar geçerli |
| `src/Licensing/LicenseRevocationStore.php` | 112, 115 | Lock/block timeout uyumsuzluğu |

## Çözüm Planı

### Adım 1: Default değeri fail-closed yap

Dosya: `config/subscription-guard.php`, satır 85

Mevcut:
```php
'fail_open_on_expired' => (bool) env('SUBGUARD_LICENSE_REVOCATION_FAIL_OPEN_ON_EXPIRED', true),
```

Yeni:
```php
'fail_open_on_expired' => (bool) env('SUBGUARD_LICENSE_REVOCATION_FAIL_OPEN_ON_EXPIRED', false),
```

Bu değişiklik, cache süresi dolduğunda lisansların "revoked" olarak kabul edilmesini sağlar. Kullanıcı isterse `true` yapabilir ama default güvenli taraf olmalı.

### Adım 2: Lock/block timeout uyumunu düzelt

Dosya: `src/Licensing/LicenseRevocationStore.php`, satır 112 ve 115

Mevcut:
```php
$lock = cache()->lock($this->cacheKey().':lock', 5);
// ...
return (bool) $lock->block(3, $callback);
```

Yeni:
```php
$lock = cache()->lock($this->cacheKey().':lock', 10);
// ...
return (bool) $lock->block(5, $callback);
```

Gerekçe:
- Lock TTL (10s) her zaman block timeout'tan (5s) en az 2 kat büyük olmalı
- Block başarılı olduğunda callback'in çalışması için yeterli süre kalmalı
- Lock TTL = block timeout + callback max süresi (tahmini 5s)

### Adım 3: Cache backend erişilemezlik durumunu yönet

Dosya: `src/Licensing/LicenseRevocationStore.php`

`isRevoked()` method'unda cache erişim hatasını yakala:

Mevcut `isRevoked()` method'unun başına try-catch ekle:

```php
public function isRevoked(int|string $licenseId): bool
{
    try {
        $state = $this->currentState();
    } catch (Throwable $e) {
        Log::channel(
            (string) config('subscription-guard.logging.licenses_channel', 'subguard_licenses')
        )->error('License revocation cache unreachable', [
            'license_id' => $licenseId,
            'error' => $e->getMessage(),
        ]);

        // Cache erişilemezse fail-closed: lisansı revoked say
        return ! (bool) config('subscription-guard.license.revocation.fail_open_on_expired', false);
    }

    // ... mevcut logic devam eder
}
```

Bu şekilde:
- Cache tamamen çöktüğünde exception yakalanır
- Default `false` ile `!false = true` → lisans revoked sayılır (güvenli taraf)
- Kullanıcı `fail_open_on_expired = true` ayarlarsa `!true = false` → lisans geçerli (bilinçli risk)
- Her cache hatası loglanır

### Adım 4: Heartbeat cache key'e prefix doğrulaması ekle

Dosya: `src/Licensing/LicenseRevocationStore.php`

`heartbeatKey()` method'u (satır 187-191) sadece license ID kullanıyor. Farklı uygulamalar aynı cache'i paylaşırsa çakışma olabilir.

Mevcut:
```php
private function heartbeatKey(int|string $licenseId): string
{
    $prefix = (string) config('subscription-guard.license.offline.cache_prefix', 'subguard:license:heartbeat:');
    return $prefix . $licenseId;
}
```

Bu zaten prefix kullanıyor ve config'den okunuyor. Ek değişiklik gerekmez - sadece dokümantasyonda multi-tenant kullanımda prefix'in benzersiz olması gerektiği belirtilmeli.

### Adım 5: Cache state doğrulaması ekle

Dosya: `src/Licensing/LicenseRevocationStore.php`

`currentState()` method'unda deserialize edilen state'in yapısını doğrula:

`normalizeState()` method'undan sonra (satır 124-140 civarı), state'in beklenen yapıda olduğunu kontrol et:

```php
private function normalizeState(mixed $raw): array
{
    // ... mevcut normalizasyon ...

    // State yapısı doğrulaması
    if (! is_array($state)) {
        return ['revoked_ids' => [], 'sequence' => 0, 'updated_at' => now()->toIso8601String()];
    }

    if (! array_key_exists('revoked_ids', $state) || ! is_array($state['revoked_ids'])) {
        $state['revoked_ids'] = [];
    }

    if (! array_key_exists('sequence', $state) || ! is_numeric($state['sequence'])) {
        $state['sequence'] = 0;
    }

    return $state;
}
```

## Doğrulama

1. `fail_open_on_expired = false` (default) ile cache expired durumunda `isRevoked()` → `true` döndüğünü doğrula
2. `fail_open_on_expired = true` (kullanıcı override) ile cache expired durumunda `isRevoked()` → `false` döndüğünü doğrula
3. Cache backend erişilemez olduğunda exception yakalandığını ve loglandığını doğrula
4. Lock/block timeout'ların uyumlu olduğunu doğrula (lock TTL > block timeout)
5. Corrupted cache state'in güvenli şekilde normalize edildiğini doğrula
6. Mevcut testlerin hala geçtiğini doğrula (`composer test`)
