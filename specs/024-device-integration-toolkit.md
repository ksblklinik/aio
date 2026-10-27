# SPEC-024: Core Device Integration Toolkit & Hardware Interoperability

- **Status**: `Completed`
- **Owner / Author**: Antigravity Platform Team
- **Tanggal Dibuat**: 2026-10-27
- **Target File / Modul**: `core/Document/EscPos.php`, `resources/js/hooks/`, `resources/js/Components/`, `public/manifest.json`, `public/sw.js`, `docs/DEVICE_INTEGRATION_GUIDE.md`

---

## 1. Problem Statement & Context
Aplikasi web modern di tingkat enterprise tidak hanya berjalan di browser laptop admin, tetapi berinteraksi langsung dengan berbagai perangkat fisik di lapangan:
- Pengemudi armada dan kurir menggunakan smartphone Android/iOS dengan pelacakan GPS live dan tanda tangan penerima barang di layar sentuh.
- Petugas canvassing dan kasir toko membawa printer thermal portabel 58mm/80mm via koneksi Bluetooth dan scanner barcode handheld USB/Bluetooth.
- Pengurus yayasan atau peserta rapat menggunakan tablet untuk memeriksa dokumen dan presensi digital.

Tanpa standarisasi di level Core, setiap modul bisnis akan mencoba memecahkan integrasi hardware secara parsial (menambal pustaka printer tidak resmi, barcode scanner yang kehilangan fokus, atau ketiadaan mode PWA saat sinyal minim). Dibutuhkan **Core Device Integration Toolkit** yang domain-agnostik, ringan (*zero-bloat*), dan dokumen panduan arsitektur perangkat yang komprehensif.

---

## 2. Goals & Non-Goals

### Goals
- **Hardware Barcode Interceptor (`resources/js/hooks/useBarcodeScanner.ts`)**:
  - Hook React yang mencegat aliran ketukan hardware barcode scanner (kecepatan <50ms antar-karakter diakhiri Enter) di mana pun fokus kursor berada, mencegah hilangnya hasil scan.
- **Digital Signature Pad (`resources/js/Components/SignaturePad.tsx`)**:
  - Komponen canvas responsif sentuhan jari & stylus untuk menangkap tanda tangan basah digital, diekspor sebagai Base64 PNG untuk lampiran dokumen atau bukti serah terima.
- **Mobile Geolocation Tracker (`resources/js/hooks/useGeoTracker.ts`)**:
  - Hook GPS mobile terintegrasi dengan opsi akurasi tinggi (`enableHighAccuracy`), pelacakan posisi berkelanjutan (`watchPosition`), dan penanganan izin lokasi.
- **Web Bluetooth ESC/POS Utility (`resources/js/utils/bluetoothPrinter.ts`) & Backend ESC/POS Builder (`core/Document/EscPos.php`)**:
  - Pembangkitan byte binary ESC/POS murni (teks tebal, rata tengah, potong kertas, laci kasir) dan utilitas Web Bluetooth API browser untuk mengirim data langsung ke printer thermal mini portabel tanpa aplikasi perantara.
- **PWA Shell (`public/manifest.json`, `public/sw.js`, `resources/views/app.php`)**:
  - Konfigurasi Web App Manifest agar web app dapat di-instal ke homescreen perangkat Android/iOS dalam mode *standalone*, lengkap dengan Service Worker shell caching.
- **Dokumentasi Komprehensif (`docs/DEVICE_INTEGRATION_GUIDE.md`)**:
  - Panduan lengkap arsitektur perangkat, protokol komunikasi, izin keamanan (HTTPS/Web APIs), dan strategi integrasi untuk seluruh skenario hardware.

### Non-Goals
- Tidak mewajibkan kompilasi APK/IPA native (tetap berbasis Progressive Web App standar web W3C).

---

## 3. Technical Architecture & Design

### Alur Integrasi Hardware & Device
```
[Perangkat Fisik] ──> [Web Standard API] ──> [AIO Frontend Toolkit] ──> [Core Backend / DB]
────────────────────────────────────────────────────────────────────────────────────────────
Barcode Scanner    ──> Keyboard Event    ──> useBarcodeScanner()      ──> Query SKU / Entity
Layar Sentuh/Pen   ──> Touch/Canvas API  ──> <SignaturePad />         ──> StorageManager (PNG)
Printer Bluetooth  ──> Web Bluetooth API ──> bluetoothPrinter.ts      ──> EscPos Binary Stream
GPS Smartphone     ──> Geolocation API   ──> useGeoTracker()          ──> Core\Geo\Geo Haversine
Homescreen Mobile  ──> PWA Manifest/SW   ──> manifest.json + sw.js    ──> Offline Shell Caching
```

---

## 4. Test Strategy
- **Unit Testing**:
  - `CoreDeviceIntegrationTest`:
    - Menguji pembuatan byte binary ESC/POS (init, bold, align, feed, cut, cash drawer).
    - Menguji integritas file PWA `manifest.json` dan Service Worker `sw.js`.
    - Menguji helper / DTO terkait.
- **Frontend Verification**:
  - Menjalankan `tsc --noEmit` untuk memvalidasi tipe TypeScript pada `useBarcodeScanner.ts`, `useGeoTracker.ts`, `SignaturePad.tsx`, dan `bluetoothPrinter.ts`.
- **System Verification**:
  - Jalankan `./scripts/verify.ps1` untuk memastikan seluruh test suite lolos 100%.

---

## 5. Atomic Task Checklist

- [x] **Phase 1: Backend ESC/POS Command Builder (`core/Document/EscPos.php`)**
  - [x] 1.1 Buat class `Core\Document\EscPos` untuk menghasilkan string byte binary ESC/POS standar.
  - [x] 1.2 Tambahkan method `toEscPos()` pada `DocumentBuilder` atau helper `escpos()`.
- [x] **Phase 2: Frontend Device Hooks & Components (`resources/js/`)**
  - [x] 2.1 Buat `resources/js/hooks/useBarcodeScanner.ts` (hardware scanner listener).
  - [x] 2.2 Buat `resources/js/hooks/useGeoTracker.ts` (GPS mobile live tracker).
  - [x] 2.3 Buat `resources/js/Components/SignaturePad.tsx` (digital signature canvas).
  - [x] 2.4 Buat `resources/js/utils/bluetoothPrinter.ts` (Web Bluetooth direct printer).
- [x] **Phase 3: PWA Mobile Shell (`public/` & `resources/views/`)**
  - [x] 3.1 Buat `public/manifest.json` (PWA metadata & icons).
  - [x] 3.2 Buat `public/sw.js` (Service Worker cache).
  - [x] 3.3 Hubungkan manifest dan service worker di `resources/views/app.php`.
- [x] **Phase 4: Comprehensive Device Documentation**
  - [x] 4.1 Buat `docs/DEVICE_INTEGRATION_GUIDE.md` yang merinci seluruh dukungan perangkat, cara kerja, dan panduan integrasi.
- [x] **Phase 5: Unit Testing & Self-Verification**
  - [x] 5.1 Buat unit test di `tests/Unit/CoreDeviceIntegrationTest.php`.
  - [x] 5.2 Jalankan `./scripts/verify.ps1`, pastikan seluruh test lolos 100% dan TypeScript valid.
  - [x] 5.3 Lakukan atomic commit dan merge ke `main`.
  - [x] 5.4 Perbarui dokumentasi spec dan `SESSION_HANDOFF.md`.
