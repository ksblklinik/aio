# SPEC-023: Universal Streamed Import-Export and Pure-PHP Printable Document Engine

- **Status**: `In Progress`
- **Owner / Author**: Antigravity Platform Team
- **Tanggal Dibuat**: 2026-10-27
- **Target File / Modul**: `core/Export/`, `core/Document/`, `core/helpers.php`

---

## 1. Problem Statement & Context
Dalam pengembangan modul bisnis berbasis data (seperti pembukuan yayasan, canvassing lapangan, e-commerce, dan manajemen armada driver), modul bisnis sering menghadapi kebutuhan pengolahan data massal dan penerbitan berkas fisik:
1. **Import & Export Data Massal**: Modul bisnis membutuhkan export rekap transaksi ke CSV atau Excel, serta import daftar anggota/katalog produk secara massal. Jika modul menulis kodenya sendiri, sering terjadi pemborosan memori (Out-Of-Memory saat data besar), inkonsistensi encoding UTF-8 di Excel, dan ketiadaan validasi baris impor yang terstandarisasi.
2. **Penerbitan Dokumen Siap Cetak (Invoices, Receipts, SK, Kuitansi)**: Modul yayasan perlu mencetak SK pengurus dan kuitansi donasi ber-QR verifikasi; modul canvassing perlu mencetak faktur tagihan dan struk thermal 58mm/80mm di printer bluetooth lapangan; modul driver perlu mencetak tanda terima order. Tanpa engine terpadu di Core, setiap modul akan menambal template HTML cetak sendiri-sendiri atau bergantung pada library Chromium/Node yang sangat berat.

Menempatkan kapabilitas Streaming Import/Export dan Printable Document Engine di Core menjamin kinerja hemat memori, format dokumen yang seragam, dan *Zero Infrastructure Leakage* (Rule 08).

---

## 2. Goals & Non-Goals

### Goals
- **Universal Streamed Import/Export Engine (`Core\Export\`)**:
  - `CsvExporter`: Export data stream hemat memori ($O(1)$ memory consumption) langsung ke output browser (`php://output`) atau berkas lokal dengan dukungan UTF-8 BOM untuk kompatibilitas Microsoft Excel.
  - `ExcelExporter`: Export spreadsheet murni PHP (SpreadsheetML XML compliant) dengan styling header, alignment, dan tipe sel numerik/tanggal tanpa library vendor pihak ketiga.
  - `CsvImporter`: Importer streaming baris demi baris dengan integrasi validasi form/field (`Validator`), pelacakan baris gagal/sukses, dan rollback safety.
- **Pure-PHP Printable Document & Receipt Engine (`Core\Document\`)**:
  - `DocumentBuilder`: Fluent builder untuk merancang dokumen siap cetak dengan pilihan layout:
    - `a4`: Invoice resmi, Surat Keputusan (SK) Pengurus, Surat Tugas Lapangan, Berita Acara.
    - `thermal_58mm` & `thermal_80mm`: Struk kasir lapangan, bukti penagihan canvassing, tanda terima top-up driver.
    - `receipt`: Kuitansi kas, bukti pembayaran donasi dengan format slip.
  - Terintegrasi otomatis dengan `qr_code()` untuk verifikasi keaslian dokumen, `barcode()` untuk pelacakan nomor berkas, dan profil organisasi tenant (`TenantSettings`).
  - Output HTML cetak presisi dengan CSS `@media print` murni tanpa page-break potong tabel.
- **Global Helpers**:
  - `exporter(): ExporterManager`
  - `document(string $layout = 'a4'): DocumentBuilder`

### Non-Goals
- Tidak menginstal binary headless browser C++/Node seperti Puppeteer atau wkhtmltopdf yang memperberat platform (*Zero External Heavy Dependencies*).

---

## 3. Technical Architecture & Design

### Alur Kerja Dokumen Siap Cetak
```
[Modul Bisnis] ──> document('a4')
                     ->header("YAYASAN KESEJAHTERAAN UMAT")
                     ->meta("No. SK", "SK/2026/001")
                     ->withQr("https://aio.test/verify/SK-001")
                     ->withBarcode("SK2026001")
                     ->addItem("Donasi Pembangunan", 1, 5000000)
                     ->watermark("LUNAS")
                     ->render();
                          │
                          ▼
             [Clean Responsive Print HTML & CSS Token Compliant]
```

### Alur Kerja Streamed Import/Export
```
[Database / Iterable] ──> CsvExporter::stream($cursor, $headers, 'export.csv') ──> php://output (0 OOM Risk)

[Uploaded File] ──> CsvImporter::parse($filePath, function($row) { ... }, $validationRules)
                         │
                         ├── Valid: Commit DB / Callback
                         └── Invalid: Catat error baris ke ErrorBag
```

---

## 4. Edge Cases & Error Scenarios
- **Kasus 1**: Karakter khusus atau simbol mata uang pada CSV berantakan di Microsoft Excel.
  - *Mitigasi*: CsvExporter menyertakan UTF-8 BOM (`\xEF\xBB\xBF`) di awal stream sehingga Excel langsung mengenali encoding UTF-8 secara akurat.
- **Kasus 2**: File CSV impor berukuran puluhan megabyte (puluhan ribu baris).
  - *Mitigasi*: CsvImporter membaca file menggunakan pointer stream `fgetcsv()` baris-demi-baris tanpa membaca seluruh file ke memori RAM sekaligus.
- **Kasus 3**: Dokumen dicetak pada printer thermal 58mm atau 80mm tetapi layout meluap ke samping.
  - *Mitigasi*: Layout `thermal_58mm` dan `thermal_80mm` memiliki lebar fixed CSS `58mm` / `80mm` dengan font monospace dan auto word-wrap.

---

## 5. Test Strategy
- **Unit Testing**:
  - `CoreImportExportAndDocumentTest`:
    - Menguji ekspor streaming CSV dan output UTF-8 BOM.
    - Menguji ekspor SpreadsheetML XML.
    - Menguji parsing streaming CSV importer dengan baris valid dan penangkapan baris error.
    - Menguji rendering layout dokumen A4, Thermal 58mm, Thermal 80mm, dan Kuitansi Receipt.
    - Menguji penyertaan QR code SVG dan Barcode SVG di dalam dokumen cetak.
    - Menguji helper global `exporter()` dan `document()`.
- **Integration & Regression Testing**:
  - Eksekusi `./scripts/verify.ps1` untuk memvalidasi test suite global (target 300+ tests, 100% pass) dan `tsc --noEmit`.

---

## 6. Atomic Task Checklist

- [ ] **Phase 1: Universal Import/Export Engine (`Core\Export\`)**
  - [ ] 1.1 Buat `Core\Export\CsvExporter` dengan kemampuan stream file dan browser download.
  - [ ] 1.2 Buat `Core\Export\ExcelExporter` dengan format SpreadsheetML XML murni PHP.
  - [ ] 1.3 Buat `Core\Export\CsvImporter` dengan parsing pointer stream dan validasi baris.
  - [ ] 1.4 Buat `Core\Export\ExporterManager` sebagai fasad terpadu.
- [ ] **Phase 2: Pure-PHP Printable Document Engine (`Core\Document\`)**
  - [ ] 2.1 Buat `Core\Document\DocumentBuilder` dengan fluent API untuk metadata, tabel item, total, watermark, dan catatan kaki.
  - [ ] 2.2 Implementasikan template layout: `a4`, `thermal_58mm`, `thermal_80mm`, dan `receipt`.
  - [ ] 2.3 Integrasikan penyematan QR code SVG (`qr_code()`) dan Barcode Code-128 (`barcode()`).
- [ ] **Phase 3: Service Providers & Helpers**
  - [ ] 3.1 Daftarkan layanan ke `core/Foundation/Application.php`.
  - [ ] 3.2 Tambahkan helper functions `exporter()` dan `document()` ke `core/helpers.php`.
- [ ] **Phase 4: Unit Testing & Self-Verification**
  - [ ] 4.1 Buat unit test komprehensif di `tests/Unit/CoreImportExportAndDocumentTest.php`.
  - [ ] 4.2 Jalankan `./scripts/verify.ps1`, pastikan seluruh test suite lolos 100%.
  - [ ] 4.3 Buat git commit atomik dan merge branch fitur ke `main`.
  - [ ] 4.4 Perbarui dokumentasi spec dan `SESSION_HANDOFF.md`.
