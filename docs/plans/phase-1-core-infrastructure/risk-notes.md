# Faz 1 Risk Notes

> **Faz**: Core Infrastructure
> **Durum**: Devam Ediyor (Phase 0 tamamlandı)
> **Güncelleme Tarihi**: 2026-03-04

## Karşılaşılan Riskler
- Composer autoload namespace ile kaynak/test namespace tutarsızlığı
- Placeholder testler nedeniyle yanlış pozitif güven
- CI matrix'te birden çok PHP sürümü nedeniyle plan kararından sapma riski

## Uygulanan Çözümler
- Composer PSR-4 namespace ve provider/alias kayıtları tek namespace'e hizalandı
- Package boot smoke testi eklendi (service provider + config)
- CI matrix PHP sürümü `8.4` olarak sabitlendi

## Gelecek Fazlar İçin Notlar
- Faz 1 migration/model implementasyonunda önce test yazımı (TDD) korunmalı
- Renewal/dunning komutları için queue-first yaklaşım kod seviyesinde uygulanmalı
- Webhook testleri için `subguard:simulate-webhook` komutu Faz 5'e kadar beklemeden local helper ile emüle edilebilir

## Technical Debt
- Workbench app dizini henüz oluşturulmadı; Testbench tabanlı mevcut kurulum yeterli olsa da ileri entegrasyon testlerinde gerekebilir
