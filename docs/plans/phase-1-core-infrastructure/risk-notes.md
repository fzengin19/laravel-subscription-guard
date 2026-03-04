# Faz 1 Risk Notes

> **Faz**: Core Infrastructure
> **Durum**: Tamamlandı
> **Güncelleme Tarihi**: 2026-03-04

## Karşılaşılan Riskler
- Composer autoload namespace ile kaynak/test namespace tutarsızlığı
- Placeholder testler nedeniyle yanlış pozitif güven
- CI matrix'te birden çok PHP sürümü nedeniyle plan kararından sapma riski
- `testbench workbench:install` komutunda non-interactive prompt kaynaklı kesinti
- Migration şemasının tek bir expansion migration dosyasına yığılması
- Foreign key tanımlarının eksik olması ve kolonların gereksiz nullable bırakılması
- Scheduler command/job altyapısının faz planına göre eksik kalması
- Webhook işleme akışının yalnızca echo-level iskelette kalması

## Uygulanan Çözümler
- Composer PSR-4 namespace ve provider/alias kayıtları tek namespace'e hizalandı
- Package boot smoke testi eklendi (service provider + config)
- CI matrix PHP sürümü `8.4` olarak sabitlendi
- Gerekli route/controller ve config eklenerek package discover hatası giderildi
- Expansion migration kaldırıldı, tüm şema doğru create migration dosyalarına dağıtıldı
- Uygun alanlarda `foreignId`/foreign key düzeni ve nullable sıkılaştırması uygulandı
- Renewal/dunning/plan-change/suspend command altyapısı ve ilgili queue job akışları eklendi
- Webhook call persistence + duplicate idempotency + async finalization akışı eklendi

## Gelecek Fazlar İçin Notlar
- Faz 2'de provider adapter implementasyonları (`iyzico`) tamamlanırken PaymentChargeJob placeholder failure dalı gerçek provider çağrısına bağlanmalı
- Faz 2/Faz 3'te webhook signature doğrulaması provider bazlı sertleştirilmeli
- Faz 3'te PayTR self-managed akışında dunning retry penceresi (2/5/7) gerçek tahsilat sonuçlarına göre finalize edilmeli
- Faz 4'te lisans kripto/doğrulama akışları mevcut core şema üzerine entegre edilmeli

## Technical Debt
- `config/subscription-guard.php` içindeki `env()` çağrıları için Larastan baseline uyarıları mevcut (faz dışı, takip edilmeli)
- `src/Concerns/Billable.php` trait kullanımına dair statik analiz uyarısı mevcut (paket tüketiminde trait kullanılınca kapanır)
