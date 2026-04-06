# FIX-08: PHPStan False-Positive Temizliği

## Problem

`composer analyse` 34 hata raporluyor:

1. **33 hata**: `config/subscription-guard.php` içindeki `env()` çağrıları - LaraSTAN `larastan.noEnvCallsOutsideOfConfig` kuralı paketin config dosyasını "config dışı" olarak algılıyor
2. **1 hata**: `src/Concerns/Billable.php` - `trait.unused` - PHPStan trait'leri doğrudan analiz etmez, kullanım harici projede olacağı için algılanamıyor

Bu hatalar gerçek bug değil ama CI/CD pipeline'da `composer analyse` başarısız olmasına neden oluyor.

## Etkilenen Dosyalar

| Dosya | Hata Sayısı | Kural |
|-------|-------------|-------|
| `config/subscription-guard.php` | 33 | `larastan.noEnvCallsOutsideOfConfig` |
| `src/Concerns/Billable.php` | 1 | `trait.unused` |
| `phpstan.neon.dist` | - | Yapılandırma dosyası |

## Çözüm Planı

### Yaklaşım Seçimi

İki seçenek var:

**A) Baseline güncelle** - Mevcut hataları baseline'a ekle
**B) PHPStan config'de kuralları ignore et** - Spesifik kuralları devre dışı bırak

**Tercih: B** - Daha temiz ve sürdürülebilir. Paket config dosyasında `env()` kullanmak Laravel paket geliştirme best practice'i. Bu bir bug değil, LaraSTAN'ın paket bağlamını anlamaması.

### Adım 1: phpstan.neon.dist'e ignore kuralları ekle

Dosya: `phpstan.neon.dist`

Mevcut:
```neon
includes:
    - phpstan-baseline.neon

parameters:
    level: 5
    paths:
        - src
        - config
        - database
    tmpDir: build/phpstan
```

Yeni:
```neon
includes:
    - phpstan-baseline.neon

parameters:
    level: 5
    paths:
        - src
        - config
        - database
    tmpDir: build/phpstan
    ignoreErrors:
        -
            identifier: larastan.noEnvCallsOutsideOfConfig
            path: config/*
        -
            identifier: trait.unused
            path: src/Concerns/*
```

Açıklama:
- `larastan.noEnvCallsOutsideOfConfig`: Sadece `config/` dizinindeki dosyalarda ignore edilir. `src/` içinde `env()` çağrısı yapılırsa hala hata verir (bu doğru davranış).
- `trait.unused`: Sadece `src/Concerns/` dizinindeki trait'ler için ignore edilir. Paket trait'leri kullanıcı projede `use` edilecek, PHPStan bunu paket bağlamında göremez.

### Adım 2: Baseline dosyasını temizle

Eğer `phpstan-baseline.neon` dosyasında bu 34 hata zaten varsa, baseline'dan kaldır (artık ignoreErrors ile yönetiliyor).

Dosya: `phpstan-baseline.neon`

Bu dosyayı oku ve `larastan.noEnvCallsOutsideOfConfig` veya `trait.unused` ile ilgili girdileri sil.

Eğer baseline'da başka hata yoksa dosyayı boş bırak:
```neon
parameters:
    ignoreErrors: []
```

### Adım 3: Doğrulama komutu çalıştır

```bash
composer analyse
```

Beklenen sonuç: 0 hata.

Eğer hala hata varsa, `phpstan.neon.dist`'teki identifier'ların doğru olduğunu kontrol et. LaraSTAN versiyonuna bağlı olarak identifier formatı değişebilir. Alternatif:

```neon
ignoreErrors:
    -
        message: "#Called 'env' outside of the config directory#"
        path: config/*
    -
        message: "#Trait .* is used zero times#"
        path: src/Concerns/*
```

## Doğrulama

1. `composer analyse` çalıştır → 0 hata
2. `src/` içine test amaçlı `env()` çağrısı ekle → PHPStan hata vermeli (ignore sadece config'de)
3. `src/` içine kullanılmayan trait ekle → PHPStan hata vermeli (ignore sadece Concerns'de)
4. Mevcut testlerin hala geçtiğini doğrula (`composer test`)
