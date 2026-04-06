# FIX-03: Metered Billing Usage Silme Race Condition

## Problem

`src/Billing/MeteredBillingProcessor.php` satır 150-152'de işlem sırası:

```php
150: $transaction->markProcessed($providerTransactionId, $providerResponse, true);
152: $usageQuery->delete();
```

**Senaryo 1 - Usage kaybı**: Satır 150 başarılı ama satır 152'deki `delete()` DB hatası verirse → transaction "processed" ama usage kayıtları hala duruyor. Sonraki metered billing cycle'da aynı usage tekrar charge edilir (**çift ücretlendirme**).

**Senaryo 2 - Orphan usage**: Satır 150 başarısız olursa ama satır 152 çalışırsa → usage silinir ama transaction processed değil. Kullanıcı ödeme yapmamış ama usage kayıtları kaybolmuş.

**Senaryo 3 - Concurrent processing**: Usage kayıtları `lockForUpdate` ile kilitlenmiyor. İki metered billing job'u aynı subscription için aynı anda çalışırsa, her ikisi de aynı usage kayıtlarını okuyup toplar ve çift charge oluşur.

**Ek sorun**: Satır 68-69'da `totalUsage <= 0` kontrolü sonuç olarak `false` döndürür ama subscription zaten query'lenmiş ve işlenmiştir - gereksiz DB yükü.

## Etkilenen Dosyalar

| Dosya | Satır | Sorun |
|-------|-------|-------|
| `src/Billing/MeteredBillingProcessor.php` | 150-152 | İşlem sırası güvensiz |
| `src/Billing/MeteredBillingProcessor.php` | 60-66 | Usage kayıtları lock'suz okunuyor |
| `src/Billing/MeteredBillingProcessor.php` | 68-69 | Zero usage check verimsiz |

## Çözüm Planı

### Adım 1: Usage kayıtlarını silme yerine "billed" olarak işaretle

`LicenseUsage` tablosuna `billed_at` nullable timestamp kolonu ekle.

Yeni migration dosyası: `database/migrations/xxxx_add_billed_at_to_license_usages_table.php`

```php
Schema::table('license_usages', function (Blueprint $table) {
    $table->timestamp('billed_at')->nullable()->after('metadata');
    $table->index(['license_id', 'metric', 'billed_at']);
});
```

Bu sayede:
- Usage kayıtları asla silinmez (audit trail korunur)
- `billed_at IS NULL` olanlar henüz faturalanmamış usage'ları gösterir
- `billed_at IS NOT NULL` olanlar zaten faturalanmış kayıtları gösterir

### Adım 2: MeteredBillingProcessor'da usage sorgusunu güncelle

Dosya: `src/Billing/MeteredBillingProcessor.php`

**Usage okuma sorgusunu değiştir** (satır 60-66 civarı):

Mevcut:
```php
$usageQuery = LicenseUsage::query()
    ->where('license_id', $licenseId)
    ->where('metric', $metric)
    ->where('period_start', '>=', $periodStart)
    ->where('period_end', '<=', $periodEnd);

$totalUsage = (float) $usageQuery->sum('quantity');
```

Yeni:
```php
$usageQuery = LicenseUsage::query()
    ->where('license_id', $licenseId)
    ->where('metric', $metric)
    ->where('period_start', '>=', $periodStart)
    ->where('period_end', '<=', $periodEnd)
    ->whereNull('billed_at')
    ->lockForUpdate();

$totalUsage = (float) $usageQuery->sum('quantity');
```

Değişiklikler:
- `whereNull('billed_at')` → sadece faturalanmamış usage
- `lockForUpdate()` → concurrent processing'e karşı koruma

### Adım 3: Silme yerine billed_at güncelleme

Dosya: `src/Billing/MeteredBillingProcessor.php`

Mevcut (satır 152):
```php
$usageQuery->delete();
```

Yeni:
```php
$usageQuery->update(['billed_at' => now()]);
```

### Adım 4: Tüm işlemi tek DB transaction içine al

Dosya: `src/Billing/MeteredBillingProcessor.php`

Mevcut `processSubscription()` method'unda charge + usage update sırasını tek transaction'a sar:

```php
DB::transaction(function () use ($transaction, $usageQuery, $providerTransactionId, $providerResponse, $subscription) {
    // 1. Transaction'ı processed olarak işaretle
    $transaction->markProcessed($providerTransactionId, $providerResponse, true);

    // 2. Usage kayıtlarını billed olarak işaretle (silme yerine)
    $usageQuery->update(['billed_at' => now()]);

    // 3. Subscription billing tarihlerini güncelle
    // ... mevcut satır 154-157 logic'i
});
```

Bu sayede:
- Herhangi bir adım başarısız olursa tamamı rollback olur
- Transaction processed ama usage silinmemiş durumu oluşamaz
- Usage silinmiş ama transaction processed değil durumu oluşamaz

### Adım 5: LicenseUsage model'ine billed_at cast ekle

Dosya: `src/Models/LicenseUsage.php`

```php
protected function casts(): array
{
    return [
        // ... mevcut cast'ler
        'billed_at' => 'datetime',
    ];
}
```

### Adım 6: FeatureGate currentUsage'ı güncelle

Dosya: `src/Features/FeatureGate.php`

`currentUsage()` method'u aylık usage toplamı hesaplarken `billed_at` ayrımı yapmamalı - hem billed hem unbilled usage toplam kullanımı gösterir. Mevcut method değişmez.

Ama eğer "kalan kullanım hakkı" hesaplanıyorsa, `billed_at` durumuna bakılmaksızın tüm kayıtlar sayılmalı. Bu zaten mevcut davranış.

## LicenseUsage Model Fillable Güncelleme

`billed_at` alanını model'in fillable veya guarded ayarlarına ekle. Mevcut model yapısına bağlı olarak:

```php
// Eğer $fillable kullanılıyorsa:
protected $fillable = [
    // ... mevcut alanlar
    'billed_at',
];
```

## Doğrulama

1. Usage kayıtlarının silinmediğini, `billed_at` ile işaretlendiğini doğrula
2. `billed_at IS NOT NULL` olan kayıtların sonraki cycle'da tekrar charge edilmediğini doğrula
3. DB transaction rollback'inin tüm işlemi geri aldığını doğrula
4. Concurrent metered billing job'larının `lockForUpdate` ile korunduğunu doğrula
5. `FeatureGate::currentUsage()` hem billed hem unbilled kayıtları saydığını doğrula
6. Mevcut testlerin hala geçtiğini doğrula (`composer test`)
