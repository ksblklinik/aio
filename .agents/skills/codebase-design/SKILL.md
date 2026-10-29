---
name: codebase-design
description: Standar Principal Software Architect untuk merancang struktur repositori, batas modul (bounded contexts), kontrak antarmuka yang dalam (deep modules), alur dependensi netral, dan pencegahan arsitektur busuk (code rot).
---

# Skill: Codebase Architecture & Modular Design

Gunakan skill ini saat:
1. Merancang modul bisnis baru (terutama modul vertikal Level 3 seperti EMR, Rawat Jalan, Farmasi Racikan, Laboratorium).
2. Mengevaluasi batas modul (*bounded contexts*) dan memutus ketergantungan melingkar (*circular dependencies*).
3. Menentukan konvensi penamaan entitas agar tetap netral platform (mendukung profit & non-profit).
4. Merefaktor kode yang mengalami *architectural rot*, pembengkakan kelas (*God Class*), atau lapisan abstraksi tipis yang tidak berguna (*shallow wrappers*).

---

## 1. Fondasi Netralitas Platform (Platform-Neutral Architecture)

Platform **aio** adalah kernel sistem operasi organisasi yang **netral domain, netral model ekonomi, dan netral infrastruktur deployment**:

### A. Netralitas Model Bisnis (Commercial vs Humanitarian/Social)
* Arsitektur dilarang keras berasumsi bahwa setiap transaksi selalu bersifat komersial (kasir kas/kartu kredit dengan margin laba).
* Arsitektur juga dilarang keras mengasumsikan bahwa sistem hanya untuk kegiatan sosial murni tanpa akuntansi biaya.
* Seluruh kontrak, skema, dan alur kerja wajib mendukung spektrum operasional penuh:
  - **Model Komersial Penuh**: Tarif berbayar, multi-asuransi (BPJS/swasta), pembagian jasa medis dokter (*split fee*), laba rugi.
  - **Model Sosial / Non-Profit / Kebencanaan**: Layanan gratis (faktur Rp 0), subsidi silang terprogram, pembiayaan berbasis dana terikat (*restricted funds/zakat/donor*), distribusi bantuan natura (*in-kind*), dan pelacakan amanah donatur.
  - **Model Hibrida**: Fasilitas yang melayani pasien umum berbayar sekaligus pasien bersubsidi penuh dalam satu buku besar yang terisolasi.

### B. Konvensi Penamaan Entitas Universal (Neutral Naming Conventions)
Gunakan istilah universal yang tidak bias pada industri tertentu atau model komersial:
* Gunakan `contacts` — bukan `customers` atau `patients` (karena entitas bisa berupa pasien, relawan, donatur, dokter, mitra, atau supplier).
* Gunakan `movements` / `inventory_transfers` — bukan `sales_orders` (karena barang bisa didistribusikan untuk bantuan gratis, pemakaian internal poli, atau penjualan apotek).
* Gunakan `facilities`, `rooms`, `spaces` — bukan istilah spesifik seperti `hotel_rooms` atau `cinema_seats` (karena mencakup ruang periksa, bed rawat, depo farmasi, posko tenda darurat, atau meja konsultasi).
* Gunakan `settlements` / `allocations` — bukan asumsi `credit_card_charge` (karena pembayaran mencakup klaim asuransi, hibah donor, transfer bank, kas, atau kompensasi internal).

### C. Netralitas Mesin Basis Data (Engine & Topology Agnostic)
* Seluruh modul bisnis (`app/Modules/*`) berkomunikasi dengan basis data murni melalui `Core\Database\Connection` menggunakan **ANSI SQL netral**.
* **Edge Baseline**: SQLite WAL mode adalah baseline untuk node offline-first/posko darurat tanpa internet.
* **Cloud Upgradable**: Dilarang menggunakan fungsi SQL proprietary atau dialek vendor tertentu di dalam modul bisnis. Kode harus dapat beralih ke PostgreSQL atau MySQL cloud tanpa modifikasi pada layer bisnis (*zero rewrite*).

---

## 2. Filosofi Inti: Deep Modules vs Shallow Wrappers

Mengadopsi prinsip desain dari John Ousterhout (*A Philosophy of Software Design*):

```
       [ SHALLOW MODULE (DILARANG) ]                     [ DEEP MODULE (STANDAR aio) ]
 ┌────────────────────────────────────────┐         ┌──────────────────────────────────────┐
 │ Antarmuka Publik Lebar (20 method tipis) │         │ Antarmuka Publik Ringkas & Sederhana │
 └───────────────────┬────────────────────┘         └───────────────────┬──────────────────┘
                     │ (wrapper tipis)                                  │ (kompleksitas masif
 ┌───────────────────▼────────────────────┐                             │  tersembunyi bersih)
 │     Implementasi Dangkal (1-to-1)       │         ┌───────────────────▼──────────────────┐
 └────────────────────────────────────────┘         │  Validasi Invarian, Transaksi Atomik, │
                                                    │  Kalkulasi FEFO, Outbox Event, Hash   │
                                                    └──────────────────────────────────────┘
```

1. **Definisi *Deep Module***:
   - Antarmuka publik (`Contracts/*RegistryInterface.php`) dibuat **sangat ringkas dan sederhana**, tetapi mengkapsulasi kompleksitas logika, validasi invarian, dan orkestrasi internal yang kokoh.
   - Contoh: `billing()->createInvoice($dto)` hanya meminta parameter penting, namun secara atomik mengeksekusi resolusi buku tarif, perhitungan split-fee, alokasi FEFO multi-batch, pencatatan jurnal akuntansi, dan perekaman event Outbox dalam 1 transaksi ACID.
2. **Larangan *Shallow Wrapper***:
   - Dilarang membuat rantai kelas perantara yang hanya meneruskan parameter 1:1 tanpa memberikan nilai tambah logika (misal: Controller $\rightarrow$ Service $\rightarrow$ Manager $\rightarrow$ Repository $\rightarrow$ DAO yang semuanya hanya meneruskan `findById`).
   - Selaras dengan [**Ponytail Pragmatism (Rule 09)**](file:///c:/aplikasi/aio/.agents/rules/09-ponytail-pragmatism.md), kelas internal di dalam modul tidak boleh dibungkus interface prematur atau lapisan DTO/Factory yang tidak memberi nilai nyata. Interface HANYA diperuntukkan bagi batas publik modul (`Contracts/*RegistryInterface.php`).
3. **The Deletion Test (Uji Penghapusan)**:
   - Sebelum mengesahkan modul baru, evaluasi: *"Jika modul ini dihapus kelak, apakah seluruh kompleksitasnya musnah bersama kontraknya, atau justru meninggalkan jejak kebocoran di puluhan file modul lain?"*
   - Jika modul lain harus tahu detail internal implementasi suatu modul, maka abstraksi tersebut bocor (*leaky abstraction*).

---

## 3. Topologi 3 Lapis Codebase (Three-Tier Topology)

Codebase `aio` diorganisasi secara ketat ke dalam 3 tingkatan hierarkis:

```
┌────────────────────────────────────────────────────────────────────────┐
│ LEVEL 3: SPECIALIZED DOMAIN MODULES (Vertikal Klinis & Lapangan)       │
│ Contoh: EMR / Rawat Jalan, Farmasi Racikan, Posko Bencana, Lab         │
└──────────────────────────────────┬─────────────────────────────────────┘
                                   │ (bergantung ke bawah)
┌──────────────────────────────────▼─────────────────────────────────────┐
│ LEVEL 2: UNIVERSAL SHARED FOUNDATIONS (Fondasi Bersama Lintas Sektor)   │
│ Contact, Finance, Inventory, Billing, Facility, Schedule, Tariff, Term │
└──────────────────────────────────┬─────────────────────────────────────┘
                                   │ (bergantung ke bawah)
┌──────────────────────────────────▼─────────────────────────────────────┐
│ LEVEL 1: CORE PLATFORM KERNEL (Infrastruktur Netral Sistem)            │
│ Auth, Multi-Tenant, Reconciler, Outbox, Crypto-Seal, Mutex, Profiler   │
└────────────────────────────────────────────────────────────────────────┘
```

### Aturan Alur Dependensi (Strict Unidirectional Flow):
1. **Dilarang Bergantung ke Atas**: Level 1 (Core) dan Level 2 (Shared Foundations) **HARAM** mengetahui, mengimpor, atau memanggil modul vertikal Level 3.
2. **Dilarang Ketergantungan Melingkar (*Zero Circular Coupling*)**: Modul sesama Level 3 tidak boleh saling mengimpor langsung secara melingkar (Modul A $\rightarrow$ Modul B $\rightarrow$ Modul A). Jika butuh interaksi, gunakan Domain Event (Outbox) atau abstraksi di Level 2.

---

## 4. Anatomi Standar Modul Bisnis (`app/Modules/<ModuleName>/`)

Setiap modul di `app/Modules/` wajib mengikuti cetak biru anatomi standar berikut:

```
app/Modules/<ModuleName>/
├── Contracts/
│   └── <ModuleName>RegistryInterface.php   # Kontrak publik yang diekspos ke modul lain
├── Controllers/
│   ├── <ModuleName>Controller.php          # Controller Web UI (Inertia React)
│   └── Api<ModuleName>Controller.php       # Controller REST API / Kiosk / Integrasi
├── Services/
│   └── <ModuleName>Service.php             # Implementasi Deep Module & Logika Bisnis
├── <ModuleName>ServiceProvider.php         # Registrasi Service Provider & Route Loader
├── module.json                             # Metadata modul, permissions, manifest
└── routes.php                              # Definisi rute web dan API terisolasi
```

### Aturan Komponen Modul:
1. **`module.json`**:
   - Wajib mencantumkan `id`, `name`, `category`, `provider`, `table_name`, dan daftar granular `permissions`.
2. **`Contracts/<ModuleName>RegistryInterface.php`**:
   - Merupakan *single entry point* bagi modul luar yang ingin berinteraksi secara sinkronus.
3. **Database Migrations**:
   - Seluruh migrasi tabel disimpan terpusat di `database/migrations/YYYY_MM_DD_XXXXXX_<nama_tabel>.sql` dengan penomoran urut kronologis.
4. **Global Helper**:
   - Modul menyediakan fungsi helper global di `core/helpers.php` (misal: `facilities()`, `billing()`, `inventory()`) yang me-resolve interface dari Container.

---

## 5. Protokol Komunikasi Lintas Modul

Untuk menjamin otonomi modul dan isolasi multi-tenant yang ketat:

### A. Komunikasi Sinkronus (Query & Command Langsung)
* Wajib melalui kontrak publik resmi (`Contracts/*RegistryInterface.php`) via Service Provider atau Global Helper.
* **Dilarang Keras**: Melakukan query SQL langsung (`SELECT * FROM other_module_table`) atau modifikasi tabel privat milik modul lain tanpa izin kontrak.

### B. Komunikasi Asinkronus (Side-Effects & Reaksi Lintas Domain)
* Wajib menggunakan **Domain Events** yang dicatat via **Transactional Outbox Engine (SPEC-041)**:
  ```php
  // Modul Billing mencatat transaksi selesai dalam satu DB transaction
  outbox()->record('billing.invoice.paid', [
      'invoice_id' => $invoiceId,
      'tenant_id' => $tenantId,
      'amount' => $totalAmount,
  ]);
  ```
* Modul lain (misal: Rekam Medis atau Farmasi) bertindak sebagai listener/subscriber yang memproses event tanpa kopling langsung.

---

## 6. Anti-God-Files & Cohesion Safeguards

1. **Batas Ukuran Berkas (Max 400 Baris)**:
   - File Controller atau Service yang mendekati atau melampaui **400 baris** wajib didekomposisi ke dalam sub-domain atau *Action Classes* spesifik.
2. **Pemisahan Peran yang Jelas**:
   - `Controllers/`: Hanya menangani HTTP Request, deserialisasi input, pemanggilan service, dan pengembalian respons (Inertia/JSON). **Dilarang menulis query SQL di Controller**.
   - `Services/`: Menangani orkestrasi bisnis, validasi aturan domain, transaksi ACID (`$this->db->transaction()`), dan penegakan invarian data.
3. **Penyimpanan State & Cache**:
   - Dilarang menyimpan state transaksional di properti statis PHP (*static variables*) untuk mencegah kebocoran data antar-request (*cross-tenant leakage*).
4. **Domain Decomposition Rule (Action Classes)**:
   - Jika sebuah Service menangani lebih dari 3 sub-entitas atau mendekati 400 baris, dekomposisi logika ke dalam *Action Classes* spesifik di sub-folder `Services/Actions/` (misal: `CreateInvoiceAction`, `ProcessRefundAction`). Service utama bertindak sebagai orkestrator ringkas tanpa pembengkakan kelas (*no God Service*).

---

## 7. Alur Kerja Audit Desain Arsitektur (4-Phase Architecture Audit)

Setiap merancang modul baru atau perubahan struktural besar, jalankan 4 fase evaluasi:

```
[1. Bounded Context & Layer] ──> [2. Deep Contract & Deletion] ──> [3. 30 Invariants & Neutrality] ──> [4. Atomic TDD Execution]
```

1. **Fase 1: Bounded Context & Layer Placement**
   - Tentukan apakah domain masuk ke **Level 1 (Core)**, **Level 2 (Shared)**, atau **Level 3 (Specialized)**.
   - Periksa apakah ada potensi duplikasi dengan modul yang sudah ada.
2. **Fase 2: Deep Contract Design & The Deletion Test**
   - Rancang interface seminimal mungkin. Sembunyikan persistensi data dan orkestrasi internal di balik implementasi service.
   - Uji dengan *The Deletion Test*: pastikan penghapusan modul tidak meninggalkan jejak destruktif di modul pemanggil.
3. **Fase 3: Kepatuhan 30 Invarian Universal (Rule 10) & Netralitas**
   - Evaluasi kepatuhan terhadap 30 Invarian Universal (konservasi fisik, debit-kredit seimbang, audit trail append-only, tenant isolation).
   - Pastikan skema data netral (dapat berjalan untuk klinik swasta profit maupun posko bencana/sosial Rp 0).
   - Pastikan query kompatibel dengan ANSI SQL (SQLite WAL edge baseline hingga PostgreSQL/MySQL cloud).
4. **Fase 4: Rencana Implementasi Bertahap (Atomic TDD)**
   - Susun checklist atomik di dokumen spec sesuai format [SPEC_TEMPLATE.md](file:///c:/aplikasi/aio/specs/templates/SPEC_TEMPLATE.md).
   - Kerjakan tugas langkah demi langkah dengan verifikasi test suite pada setiap tahapan.
