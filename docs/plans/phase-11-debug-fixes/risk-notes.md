# Phase 11: Debug Fixes - Risk Notes

> Faz başlatılmadan önce oluşturuldu. Tamamlandıkça güncellenecek.

## Bilinen Riskler

### Task 1: Mock Default Değişikliği
- `.env` dosyasında `IYZICO_MOCK=true` / `PAYTR_MOCK=true` olmayan mevcut kurulumlar etkilenecek
- **Azaltma**: `.env.test` güncellenerek test suite'i korunacak

### Task 2: Float Cast Eklenmesi
- Mevcut string karşılaştırmaları (`=== '0.00'` gibi) kırılabilir
- **Azaltma**: Tam test suite çalıştırılacak

### Task 4: advanceBillingDate Refactor
- 4 farklı call site güncellenmeli, birinin atlanması sessiz hata yaratır
- **Azaltma**: Her call site için ayrı test senaryosu

### Task 9: Facade Kaldırma
- Dış uygulamalar facade'i kullanıyor olabilir
- **Azaltma**: Mevcut testler ve PHPStan kontrol edecek
