# AGENTS.md - Laravel Subscription Guard

> **Proje**: Laravel Subscription Guard
> **Tip**: Laravel Package (Payment + Licensing)
> **Versiyon**: Development

---

## Proje Özeti

Laravel için ödeme entegrasyonu (iyzico + PayTR) ve lisans yönetimi içeren modüler paket.

---

## Planlama Sistemi

### Klasör Yapısı

```
docs/plans/
├── master-plan.md                    # Ana yol haritası (kod YOK)
├── _archive/                         # Eski planlar
│   └── 2026-03-03-master-plan-v1.2-full.md
├── phase-1-core-infrastructure/
│   ├── plan.md                       # Detaylı faz planı
│   ├── work-results.md               # [FAZ SONRASI] Yapılanlar
│   └── risk-notes.md                 # [FAZ SONRASI] Riskler
├── phase-2-iyzico-provider/
│   ├── plan.md
│   ├── work-results.md
│   └── risk-notes.md
├── phase-3-paytr-provider/
│   ├── plan.md
│   ├── work-results.md
│   └── risk-notes.md
├── phase-4-licensing-system/
│   ├── plan.md
│   ├── work-results.md
│   └── risk-notes.md
├── phase-4-1-implementation-closure/
│   ├── plan.md
│   ├── work-results.md
│   └── risk-notes.md
├── phase-5-integration-testing/
│   ├── plan.md
│   ├── work-results.md
│   └── risk-notes.md
├── phase-6-security-hardening/
│   ├── plan.md
│   ├── work-results.md
│   └── risk-notes.md
├── phase-7-code-simplification/
│   ├── plan.md
│   ├── work-results.md
│   └── risk-notes.md
└── phase-8-iyzico-live-sandbox-validation/
    ├── plan.md
    ├── work-results.md
    └── risk-notes.md
```

### Planlama Kuralları

1. **Master Plan**: Sadece yol haritası ve başlıklar, KOD YOK (kod bloğu/example snippet dahil)
2. **Faz Planları**: Her faz için detaylı plan, kod örnekleri olabilir
3. **Work Results**: Faz tamamlandıktan sonra yazılır
4. **Risk Notes**: Faz tamamlandıktan sonra yazılır
5. **Dosya Disiplini**: Her faz klasöründe `plan.md`, `work-results.md`, `risk-notes.md` zorunlu

### Gizli Bilgi ve Env Dosyaları

1. **Env dosyaları AI Assistant'a kapalıdır**: `.env`, `.env.*`, `*.env` ve benzeri dosyalar okunmaz, diff'i alınmaz, içeriği gösterilmez.
2. **Env kontrolü kullanıcıdadır**: Credential veya env doğrulaması gerekirse kullanıcı kendi tarafında kontrol eder ve gerekli sonucu paylaşır.
3. **Secret hijyeni**: Gerçek secret içeren env dosyaları commit akışına dahil edilmez; gerekli yönlendirme kullanıcı tarafından yapılır.

### Faz Başlatma Protokolü

Bir faza başlamadan önce:

1. **Dosya varlığını doğrula**: İlgili faz klasöründe `plan.md`, `work-results.md`, `risk-notes.md` mevcut mu kontrol et
2. **Master plan'ı oku**: Genel yol haritasını anla
3. **Tüm önceki fazların work-results.md'lerini oku**: Neler yapıldı
4. **Tüm önceki fazların risk-notes.md'lerini oku**: Dikkat edilmesi gerekenler
5. **İlgili faz planını oku**: Detaylı hedefler ve çıktılar
6. **Todo listesi oluştur**: Faz planındaki maddelerden

### Faz Tamamlama Protokolü

Bir faz tamamlandığında:

1. **work-results.md yaz**:
   - Neler yapıldı
   - Hangi dosyalar oluşturuldu
   - Hangi sorunlar çözüldü
   - Test sonuçları

2. **risk-notes.md yaz**:
   - Karşılaşılan riskler
   - Nasıl çözüldü
   - Gelecek için notlar
   - Technical debt

3. **Master plan durumunu güncelle**:
   - Faz durumu (Planlama/Yürütülüyor/Bitti)
   - Bir sonraki fazın bağımlılık doğrulaması

---

## Geliştirme Fazları

| Faz | Süre | Durum | Plan |
|-----|------|-------|------|
| 1. Core Infrastructure | 4 hafta | Bitti | [plan.md](phase-1-core-infrastructure/plan.md) |
| 2. iyzico Provider | 4 hafta | Bitti | [plan.md](phase-2-iyzico-provider/plan.md) |
| 3. PayTR Provider | 3 hafta | Bitti | [plan.md](phase-3-paytr-provider/plan.md) |
| 4. Licensing System | 5 hafta | Bitti | [plan.md](phase-4-licensing-system/plan.md) |
| 4.1. Implementation Closure | 2 hafta | Bitti | [plan.md](phase-4-1-implementation-closure/plan.md) |
| 5. Integration & Testing | 4 hafta | Bitti | [plan.md](phase-5-integration-testing/plan.md) |
| 6. Security Hardening | 3 hafta | Bitti | [plan.md](phase-6-security-hardening/plan.md) |
| 7. Code Simplification | 3 hafta | Bitti | [plan.md](phase-7-code-simplification/plan.md) |
| 8. iyzico Live Sandbox Validation | 3 hafta | Bitti | [plan.md](phase-8-iyzico-live-sandbox-validation/plan.md) |

**Toplam Süre**: 31 hafta

---

## Teknoloji Stack

| Katman | Teknoloji |
|--------|-----------|
| Framework | Laravel 11/12 |
| PHP | 8.4+ |
| Payment SDK | iyzico/iyzipay-php |
| Crypto | ext-sodium (Ed25519) |
| Testing | Pest PHP |
| Package Tools | spatie/laravel-package-tools |

---

## Kritik Noktalar

### Ödeme Sistemi
- iyzico/PayTR proration desteklemiyor → Credit sistemi
- Card tokens saklanmalı → provider_card_token, provider_customer_token
- Webhook idempotency zorunlu → webhook_calls tablosu

### Lisans Sistemi
- PKV kullanma → Ed25519 veya RSA-2048
- Offline validation'da heartbeat şart → Weekly check
- Subscription → License bridge → Event-Listener pattern

### Türkiye Pazarı
- E-Fatura entegrasyonu → TransactionCompleted event
- Taksitli abonelik → Manuel recurring + card token
- KDV yönetimi → tax_amount, tax_rate fields

---

## Kod Standartları

- PSR-12 formatting
- Strict types: `declare(strict_types=1);`
- Type hints everywhere
- No `@ts-ignore`, `as any`
- Soft deletes for financial tables
- Events for cross-domain communication

---

## Test Stratejisi

| Test Tipi | Araç | Coverage |
|-----------|------|----------|
| Unit | Pest | %90+ |
| Integration | Pest | Provider başına |
| Feature | Pest | E2E senaryolar |
| Webhook | Custom Command | Local test |

---

## Dokümantasyon

| Dosya | İçerik |
|-------|--------|
| README.md | Kurulum, hızlı başlangıç |
| docs/INSTALLATION.md | Detaylı kurulum |
| docs/CONFIGURATION.md | Config seçenekleri |
| docs/PROVIDERS.md | Provider entegrasyonu |
| docs/LICENSING.md | Lisans sistemi |
| docs/RECIPES.md | Yaygın senaryolar |

---

## Komutlar

```bash
# Test
composer test
composer test-coverage

# Static Analysis
composer analyse

# Format
composer format

# Migration
php artisan migrate

# Webhook Simulation
php artisan subscription-guard:simulate-webhook iyzico payment.success
```

---

## Bağımlılıklar

| Paket | Versiyon | Amaç |
|-------|----------|------|
| iyzico/iyzipay-php | ^2.x | iyzico SDK |
| ext-sodium | * | Ed25519 crypto |
| laravel/framework | ^11.0\|^12.0 | Laravel |
| spatie/laravel-package-tools | ^1.0 | Package skeleton |
| pestphp/pest | ^2.0 | Testing |

---

## İletişim ve Sorumluluk

- **Proje Sahibi**: [User]
- **Geliştirici**: AI Assistant (Sisyphus)
- **Review**: User onayları zorunlu

---

## Değişiklik Günlüğü

### 2026-03-03
- AGENTS.md oluşturuldu
- Planlama sistemi entegre edildi
- 5 faz planı oluşturuldu
- Master plan temizlendi (kodlar kaldırıldı)

### 2026-03-09
- Faz dizini ve durum tablosu Faz 6-8 ile hizalandı
- Plan durum standardı `Planlama/Yürütülüyor/Bitti` olarak netleştirildi
- Faz 8 live sandbox isolation ve runtime sahipliği tamamlandı
