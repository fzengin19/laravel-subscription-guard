# FIX-05: addMonth() Ay Sonu Kayması

## Problem

`src/Subscription/SubscriptionService.php` birden fazla yerde `addMonth()` kullanıyor:

- Satır 392: `$nextBillingDate->copy()->addMonth()`
- Satır 394: `now()->addMonth()`
- Satır 549-555: `recordWebhookTransaction` içinde aynı pattern

Carbon'un `addMonth()` davranışı:
- 31 Ocak + 1 ay = 28 Şubat (overflow yok, aya sığdırır)
- 28 Şubat + 1 ay = 28 Mart (artık **31 Mart değil**)
- 28 Mart + 1 ay = 28 Nisan
- ...sonsuza kadar 28'inde kalır

**Sonuç**: 31 Ocak'ta başlayan bir abonelik, Şubat'tan itibaren kalıcı olarak ayın 28'ine kayar. Müşteri 3 gün daha kısa hizmet alır.

## Etkilenen Dosyalar

| Dosya | Satır | Kullanım |
|-------|-------|----------|
| `src/Subscription/SubscriptionService.php` | 392 | `handlePaymentResult` - başarılı ödeme sonrası |
| `src/Subscription/SubscriptionService.php` | 394 | `handlePaymentResult` - fallback |
| `src/Subscription/SubscriptionService.php` | 549 | `recordWebhookTransaction` - webhook billing date |
| `src/Subscription/SubscriptionService.php` | 551-555 | `recordWebhookTransaction` - fallback |

## Çözüm Planı

### Yaklaşım: Orijinal billing gününü koru

Subscription tablosunda veya metadata'da orijinal billing gününü (day of month) sakla ve her renewal'da bu güne göre hesapla.

### Adım 1: Subscription'a billing_anchor_day ekle

Yeni migration: `database/migrations/xxxx_add_billing_anchor_day_to_subscriptions_table.php`

```php
Schema::table('subscriptions', function (Blueprint $table) {
    $table->unsignedTinyInteger('billing_anchor_day')->nullable()->after('billing_interval');
});
```

Bu kolon, aboneliğin "ayın kaçında" faturalanacağını tutar (1-31).

### Adım 2: Helper method oluştur

Dosya: `src/Subscription/SubscriptionService.php`

Yeni private method:

```php
private function advanceBillingDate(Carbon $currentDate, ?int $anchorDay = null): Carbon
{
    if ($anchorDay === null || $anchorDay < 1 || $anchorDay > 31) {
        $anchorDay = $currentDate->day;
    }

    $next = $currentDate->copy()->addMonthNoOverflow();
    $maxDay = $next->daysInMonth;

    // Anchor gün ayın son gününden büyükse, ayın son gününü kullan
    // Ama bu geçici - sonraki ayda 31 gün varsa 31'e geri döner
    $next->day = min($anchorDay, $maxDay);

    return $next;
}
```

`addMonthNoOverflow()` Carbon method'u overflow'u engeller (31 Ocak → 28 Şubat). Ardından anchor day'e göre günü ayarlarız. Bu sayede:
- 31 Ocak (anchor=31) → 28 Şubat (max 28) → 31 Mart (anchor=31, 31 var) → 30 Nisan (max 30)
- Her ay, o ayın izin verdiği maksimum gün ile anchor gününün minimum'unu alır

### Adım 3: Subscription oluşturulurken anchor day'i kaydet

Dosya: `src/Subscription/SubscriptionService.php`, `create()` method'u (satır 43-63)

`Subscription::query()->create([...])` içine ekle:

```php
'billing_anchor_day' => (int) now()->day,
```

### Adım 4: handlePaymentResult'da addMonth() yerine helper kullan

Dosya: `src/Subscription/SubscriptionService.php`, satır 389-395

Mevcut:
```php
$nextBillingDate = $subscription->getAttribute('next_billing_date');

if ($nextBillingDate instanceof Carbon) {
    $subscription->setAttribute('next_billing_date', $nextBillingDate->copy()->addMonth());
} else {
    $subscription->setAttribute('next_billing_date', now()->addMonth());
}
```

Yeni:
```php
$nextBillingDate = $subscription->getAttribute('next_billing_date');
$anchorDay = $subscription->getAttribute('billing_anchor_day');

if ($nextBillingDate instanceof Carbon) {
    $subscription->setAttribute(
        'next_billing_date',
        $this->advanceBillingDate($nextBillingDate, is_numeric($anchorDay) ? (int) $anchorDay : null)
    );
} else {
    $subscription->setAttribute(
        'next_billing_date',
        $this->advanceBillingDate(now(), is_numeric($anchorDay) ? (int) $anchorDay : null)
    );
}
```

### Adım 5: recordWebhookTransaction'da da aynı değişiklik

Dosya: `src/Subscription/SubscriptionService.php`, satır 549-555

Aynı pattern'i `recordWebhookTransaction` method'unda da uygula. `result->nextBillingDate` geldiğinde provider'ın gönderdiği tarihi kullan (mevcut davranış). Gelmediğinde `advanceBillingDate` kullan.

Mevcut:
```php
if ($result->nextBillingDate !== null && $result->nextBillingDate !== '') {
    $subscription->setAttribute(
        'next_billing_date',
        Carbon::parse($result->nextBillingDate, $this->billingTimezone())
            ->setTimezone((string) config('app.timezone', 'UTC'))
    );
} else {
    $nextBillingDate = $subscription->getAttribute('next_billing_date');
    if ($nextBillingDate instanceof Carbon) {
        $subscription->setAttribute('next_billing_date', $nextBillingDate->copy()->addMonth());
    } else {
        $subscription->setAttribute('next_billing_date', now()->addMonth());
    }
}
```

Yeni (else bloğu):
```php
} else {
    $nextBillingDate = $subscription->getAttribute('next_billing_date');
    $anchorDay = $subscription->getAttribute('billing_anchor_day');
    $anchor = is_numeric($anchorDay) ? (int) $anchorDay : null;

    if ($nextBillingDate instanceof Carbon) {
        $subscription->setAttribute('next_billing_date', $this->advanceBillingDate($nextBillingDate, $anchor));
    } else {
        $subscription->setAttribute('next_billing_date', $this->advanceBillingDate(now(), $anchor));
    }
}
```

### Adım 6: Mevcut abonelikler için geriye dönük uyumluluk

`billing_anchor_day` nullable olduğu için mevcut abonelikler `null` olacak. `advanceBillingDate` method'u `null` anchor durumunda mevcut günü kullanır - bu mevcut davranışla aynı (drift devam eder ama yeni abonelikler korunur).

İsteğe bağlı: Mevcut aboneliklerin `billing_anchor_day`'ini doldurmak için bir Artisan komutu yazılabilir, ama bu phase'in kapsamı dışında.

## Doğrulama

1. 31 Ocak'ta başlayan abonelik: Şubat → 28, Mart → 31, Nisan → 30 doğrulaması
2. 30 Ocak'ta başlayan abonelik: Şubat → 28, Mart → 30, Nisan → 30 doğrulaması
3. 28 Şubat'ta başlayan abonelik (artık yıl olmayan): Mart → 28 doğrulaması
4. `billing_anchor_day = null` olan aboneliklerin mevcut davranışla aynı çalıştığını doğrula
5. Provider'dan gelen `nextBillingDate`'in override ettiğini doğrula (iyzico kendi tarihini gönderir)
6. Mevcut testlerin hala geçtiğini doğrula (`composer test`)
