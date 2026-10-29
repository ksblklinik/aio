# Rule 10: Universal Invariants & Cross-Entity Integrity Charter

## Prinsip Dasar
Platform **aio** adalah sistem operasi entitas netral yang melayani spektrum luas organisasi:
* **Entitas Profit**: Faskes swasta, apotek komersial, lab mandiri, klinik berbayar.
* **Entitas Non-Profit**: Klinik dhuafa/amal, puskesmas/posyandu pembantu, posko medis tanggap bencana (NGO), lembaga penyalur donasi/zakat.

Meskipun model bisnis dan alur operasional di lapangan bervariasi, sistem diatur oleh **30 Hukum Invarian Universal** yang tidak boleh dilanggar dalam kondisi apapun (*non-negotiable data invariants*).

---

## 30 Hukum Invarian Universal (5 Lapisan Integritas)

### Lapisan I: Sumber Daya, Ekonomi & Amanah Fisik (Physical, Economic & Trust Reality)
1. **Konservasi Fisik & Logistik**: $\text{Stok Akhir} = \text{Stok Awal} + \sum \text{Masuk} - \sum \text{Keluar} \pm \text{Opname}$. Stok fisik tidak boleh bernilai negatif ($< 0$).
2. **Akuntabilitas Finansial & Sumber Dana**: $\text{Debit} = \text{Kredit}$ pada setiap jurnal. $\text{Saldo Kas Buku} = \sum \text{Arus Masuk Riil} - \sum \text{Arus Keluar Riil}$.
3. **Kapasitas Ruang & Waktu**: Satu bed, kamar, slot janji temu, atau antrean hanya dihuni oleh 1 subjek pada rentang waktu yang sama (*anti-overlapping capacity*).
4. **Pagu Hak & Kompensasi Stakeholder**: $\sum \text{Alokasi Hak} \le \text{Pagu Komponen Biaya / Anggaran Program}$.
19. **Presisi Aritmatika Tetap**: Seluruh nilai moneter dan kuantitas fisik dihitung dengan presisi tetap (*fixed-point / base integer sub-units*). Dilarang menggunakan *binary floating-point* pada kalkulasi mutasi buku besar.
24. **Silsilah Data & Rantai Penguasaan (Provenance & Chain of Custody)**: Setiap transformasi, peracikan, pemecahan, atau penyaluran sumber daya wajib memiliki jejak silsilah asal-usul yang tak terputus (*unbroken lineage graph*) sampai ke simpul penerimaan awal (*origin source / lot / grant*).
25. **Integritas Amanah & Dana Terikat (Purpose-Bound Fiduciary Invariance)**: Dana terikat program tertentu (Zakat, Wakaf, Donasi Bencana, Deposit Pasien, Escrow Klaim) dilarang digunakan untuk membiayai program lain tanpa otorisasi formal ($\sum \text{Realisasi}_X \le \text{Plafon Penerimaan}_X$). Dilarang terjadi defisit kantong dana atau subsidi silang ilegal.

### Lapisan II: Keabsahan, Privasi & Kepercayaan Hukum (Trust & Legal Boundaries)
5. **Kedaulatan Multi-Tenant**: Zero Cross-Tenant Leakage ($\text{child.tenant\_id} \equiv \text{parent.tenant\_id}$) dan No Orphan Records.
6. **Segel Kriptografis Dokumen**: Dokumen resmi/publik disegel dengan kanonikal SHA-256 + HMAC. Satu karakter berubah merusak keabsahan segel.
7. **Kedaulatan Privasi (UU PDP / GDPR)**: Data pribadi sensitif yang masa retensinya habis wajib di-*crypto-shred* permanen (penghancuran DEK). Dilarang membocorkan data PII ke log umum.
8. **Imutabilitas Rantai Audit**: Log audit terhubung dalam rantai hash kriptografis (*append-only*). Tabel audit dilarang menerima DML `UPDATE` atau `DELETE`.
22. **Ketangkasan Kriptografis & Rotasi Kunci**: Enkripsi dan tanda tangan mendukung rotasi kunci (*re-keying*) dan peningkatan algoritma tanpa membatalkan keabsahan historis dokumen lampau.
27. **Segregasi Tugas & Otorisasi Ganda (Separation of Duties & Dual-Control)**: Aksi berdampak kritis (peresepan & penyerahan narkotika/zat berbahaya, penghapusan kerugian/write-off besar, pembayaran di atas ambang batas) wajib melibatkan minimal **2 aktor independen berbeda peran** (*maker-checker principle*).
29. **Mandat Persetujuan Tindakan & Akses Data (Informed Consent & Action Mandate)**: Tindakan invasif, pembedahan, transfusi, atau transmisi rekam medis ke pihak ketiga wajib memiliki berkas persetujuan (*consent directive*) yang sah dan aktif dari subjek/wali.

### Lapisan III: Waktu, Status & Preservasi Riwayat (Temporal & State Integrity)
9. **Monotonisitas Mesin Keadaan**: Siklus hidup entitas bergerak searah (*Directed Acyclic Graph*). Status terminal (`completed`, `cancelled`, `shredded`) dilarang bertransisi mundur tanpa transaksi pembalik resmi.
10. **Kebekuan Periode Tertutup**: Periode pembukuan/fiskal yang telah berstatus `closed/audited` bersifat beku mutlak (read-only). Transaksi susulan wajib dialokasikan ke periode aktif berjalan.
11. **Kompensasi Non-Destruktif**: Catatan transaksi buku besar yang telah ter-commit bersifat *append-only*. Koreksi atau pembatalan wajib melalui mutasi pembalik (*inverse compensation record*), dilarang melakukan `DELETE` fisik.
26. **Kronologi Subjek & Kausalitas Biologis (Subject Chronology & Biological Sequence)**: Rekam kejadian pada subjek wajib terikat urutan logis dan rentang hidup ($t_{\text{lahir}} \le t_{\text{registrasi}} \le t_{\text{tindakan}} \le t_{\text{keluar / wafat}}$). Pemberian obat dilarang mendahului resep dokter; pemulangan dilarang mendahului admisi.
28. **Karantina Kelaikan & Rantai Mutu (Quarantine, Recall & Safety Fitness)**: Sumber daya yang kedaluwarsa ($t > t_{\text{exp}}$), ditarik otoritas (*batch recall*), rusak rantai dingin (*broken cold chain*), atau alat medis habis masa kalibrasi terkunci fisis dari alokasi kasir, farmasi, atau tindakan.
30. **Bebas Tanggungan Terminasi (Terminal Discharge & Obligation Clearance)**: Penutupan status kunjungan, *discharge* rawat inap, atau demobilisasi posko bantuan wajib memverifikasi nol tanggungan terbuka (*zero dangling liabilities, open IV drip, unreturned equipment, unliquidated advances*).

### Lapisan IV: Distribusi, Jaringan & Konkurensi (Execution & Distributed Integrity)
12. **Ketahanan Idempotensi**: Eksekusi mutasi dengan *idempotency key* yang sama menghasilkan efek tepat satu kali ($f(f(x)) = f(x)$).
13. **Isolasi Konkurensi Atomik**: Mutasi sumber daya terbatas wajib diserialisasi via Database Transaction + Row Lock / Mutex (SPEC-037) untuk mencegah *Lost Update* atau *Race Conditions*.
14. **Kausalitas Waktu & Outbox Dual-Write**: Urutan kausal waktu dijamin ($t_{\text{effect}} \ge t_{\text{cause}}$). Mutasi bisnis lokal dan penyimpanan event Outbox (SPEC-041) wajib terikat dalam satu transaksi ACID ($P(\text{Event} \mid \text{Commit}) = 1$).
15. **Konvergensi Replikasi Deterministik**: Sinkronisasi multi-node (Edge $\leftrightarrow$ Cloud) wajib menggunakan resolusi konflik deterministik (State CRDT / Vector Clock) tanpa *silent overwrite*.

### Lapisan V: Batas Akses, Masukan & Kelangsungan Mesin (Boundary & Survival)
16. **Otorisasi Eksplisit & Non-Ambient Authority**: Operasi sensitif wajib dijalankan atas dasar izin/peran aktif. Menolak eksekusi anonim atau wewenang implisit (*zero ambient authority*).
17. **Validasi Batas Domain & Fail-Fast**: Masukan data dari batas luar (UI, barcode, API eksternal) wajib divalidasi terhadap batas fisis domain (*fail-fast at boundary*).
18. **Batas Sumber Daya Terikat**: Seluruh query, paginasi, alokasi memori, dan worker queue wajib memiliki batas atas terikat (*strictly bounded $O(1)$ space/time complexity*) untuk mencegah OOM crash.
20. **Pembendungan Radius Ledakan**: Kegagalan integrasi pihak ketiga (bridging BPJS, SatuSehat, payment gateway) dilarang melumpuhkan operasi primer lokal (*Circuit Breaker & Graceful Fallback*).
21: **Keterpulihan Bencana Terverifikasi**: File backup wajib diuji pulih otomatis secara berkala untuk membuktikan target RPO/RTO. File database wajib lolos uji integritas fisik (`PRAGMA integrity_check`).
23: **Evolusi Skema Non-Destruktif**: Perubahan skema basis data wajib mengikuti pola *Expand and Contract*. Kolom lama dilarang dihapus sebelum seluruh node edge bertransisi.

---

## 8 Matriks Mitigasi Benturan (Tension Points)

1. **Crypto-Shredding vs Imutabilitas Audit (Inv 7 vs 8)**:
   Log audit hanya mencatat ciphertext + hash. Data PII dienkripsi dengan DEK. Saat hak dilupakan dipicu, cukup hancurkan DEK di `tenant_crypto_keys`. Rantai hash audit tetap utuh, data pribadi musnah tak terbaca.
2. **Buku Tutup vs Transaksi Susulan Offline (Inv 10 vs 15)**:
   Menerapkan *Two-Date Ledger Accounting*. `transacted_at` mencatat tanggal fisik kejadian, namun `posted_at` dialokasikan ke periode aktif berjalan dengan penanda `prior_period_adjustment`.
3. **Isolasi Mutex vs Kemandirian Node Offline (Inv 13 vs 15)**:
   Menerapkan *Key-Space Partitioning* (alokasi range prefix ID per node). Mutex bekerja 100% lokal tanpa tabrakan ID saat sinkronisasi ke cloud.
4. **Batas Sumber Daya vs Penumpukan Outbox (Inv 18 vs 14)**:
   Menerapkan *Outbox Event Coalescing* dan *Base State Snapshotting* saat kapasitas penyimpanan lokal mencapai ambang batas 85%.
5. **Konservasi Stok vs Realitas Kerusakan Fisik (Inv 1 vs 11)**:
   Dilarang mengedit langsung `current_stock`. Selisih fisik dunia nyata wajib dicatat via Berita Acara Opname sebagai mutasi penyesuaian baru (`shrinkage_out` atau `surplus_in`) dan akun beban kerugian pada buku besar.
6. **Benturan Gawat Darurat vs Otorisasi Ganda / Informed Consent (Inv 27 & 29 vs Tindakan Cito Penyelamatan Jiwa)**:
   Menerapkan **Emergency Break-Glass Protocol**. Sistem mengizinkan *single-actor emergency bypass* dengan status triase merah (`priority = 'cito'`), mencatat jejak audit tidak terhapuskan, dan otomatis menerbitkan kewajiban Tier 2 untuk *retroactive peer review* dan pengesahan komite medik dalam 24 jam.
7. **Benturan Likuiditas Tanggap Bencana vs Dana Terikat (Inv 25 vs Kebutuhan Darurat Lapangan)**:
   Menerapkan **Explicit Inter-Program Bridging Loan (*Dana Talangan Terjadwal*)**. Dilarang melakukan subsidi silang diam-diam. Sistem mencatat pinjaman antar-dompet program secara formal dengan jatuh tempo pelunasan otomatis saat dana donor masuk.
8. **Benturan Silsilah Lot vs Pencampuran Obat Racikan / Bulk Sembako (Inv 24 vs Pembuatan Puyer / Repacking)**:
   Menerapkan **Composite Lot Merging (*Fractional Lineage DAG*)**. Sistem menerbitkan lot komposit baru dengan metadata persentase komposisi induk (`parent_lots: [{lot: A, pct: 40}, {lot: B, pct: 60}]`) sehingga penelusuran silsilah tetap 100% utuh.

---

## 3 Tingkatan Gerbang Eksekusi (Tiering)

1. **Tier 1 (Sinkronus Real-Time $< 5\text{ms}$)**: Dieksekusi di dalam transaksi DB ACID (Inv 1, 2, 3, 11, 13, 16, 17, 19, 24, 25, 26, 27, 28, 29, 30).
2. **Tier 2 (Asinkronus Outbox $< 500\text{ms}$)**: Dieksekusi oleh queue worker (Inv 6, 8, 9, 12, 14, 27-notif, 28-alert).
3. **Tier 3 (Audit Periodik / On-Demand via `ReconciliationManager`)**: Dieksekusi berkala atau saat tutup buku/sync (Inv 4, 5, 7, 10, 15, 18, 20, 21, 22, 23, 24-audit, 25-audit).

---

## 4. Protokol Registrasi Auditor Modul Baru

Setiap modul Level 2 atau Level 3 yang memperkenalkan entitas transaksional baru wajib memastikan invarian terkait diaudit:
1. **Implementasi Auditor**: Buat class auditor yang mengimplementasikan `Core\Reconciliation\Contracts\ReconciliationAuditorInterface`.
2. **Registrasi di Kernel**: Daftarkan class auditor tersebut di `Core\Reconciliation\ReconciliationManager` melalui ServiceProvider modul terkait.
3. **Penyediaan Auto-Heal**: Sertakan logika sinkronisasi otomatis (*healing*) untuk deviasi yang aman diperbaiki tanpa intervensi manual.
