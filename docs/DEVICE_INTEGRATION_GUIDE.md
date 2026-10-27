# Panduan Integrasi Perangkat & Interoperabilitas Hardware (Device Integration Guide)

Dokumen ini mendefinisikan arsitektur, standar teknis, dan panduan penggunaan integrasi perangkat keras (*hardware devices*) pada platform **aio**.

---

## 1. Filosofi Desain: Web-First, Hardware-Interoperable
Platform aio menggunakan pendekatan **Progressive Web App (PWA)** dan **W3C Web Standards APIs**. Hal ini memungkinkan satu basis kode (*single codebase*) berjalan secara mulus di berbagai perangkat tanpa memerlukan kompilasi aplikasi native (APK/IPA) yang rumit, dengan keunggulan:
- **Zero Install Friction**: Aplikasi dapat diakses langsung via browser dan dapat di-instal ke *homescreen* Android, iOS, Windows, dan macOS layaknya aplikasi native.
- **Hardware Interoperability**: Berkomunikasi langsung dengan perangkat periferal (printer bluetooth, scanner barcode, GPS, stylus pen) melalui API standar browser modern.
- **Domain Agnostic**: Seluruh utilitas device dirancang murni sebagai *primitives* (tidak terikat pada jenis bisnis tertentu).

---

## 2. Matriks Form Factor & Panduan Tampilan Layar

```
┌─────────────────────┬───────────────────────┬────────────────────────┐
│  Desktop / Laptop   │   Tablet / iPad / POS │  Smartphone / Handheld │
│  (1280px ke atas)   │   (768px - 1024px)    │   (360px - 480px)      │
├─────────────────────┼───────────────────────┼────────────────────────┤
│ • Manajemen Data    │ • Kasir Toko / POS    │ • Kurir & Driver Logistik│
│ • Rekap Akuntansi   │ • Presensi Rapat      │ • Sales Canvassing     │
│ • Keyboard & Mouse  │ • Tanda Tangan Stylus │ • Operasi Satu Tangan  │
└─────────────────────┴───────────────────────┴────────────────────────┘
```

### Standar Ergonomi Mobile (Smartphone)
1. **Touch Target Minimal**: Seluruh tombol aksi utama di layar sentuh wajib berukuran minimal **48x48 px** agar nyaman ditekan jempol satu tangan saat di lapangan.
2. **OLED / Battery Saver**: Tema gelap (*Dark Mode*) murni berbasis CSS semantic tokens (`bg-canvas-dark`, `text-main-dark`) untuk menghemat konsumsi baterai smartphone driver/canvasser di luar ruangan.
3. **Anti-Glare Contrast**: Rasio kontras teks minimal 4.5:1 untuk keterbacaan di bawah sinar matahari langsung.

---

## 3. Integrasi Printer Thermal & Dokumen Cetak

### A. Printer Thermal Portabel (58mm & 80mm via Web Bluetooth)
Printer thermal portabel (seperti Panda, Eppos, Xprinter, Sunmi, Imin, Zjiang) digunakan oleh petugas lapangan untuk mencetak struk kasir, tanda terima setoran, dan bukti pembayaran QRIS.

#### 1. Pembangkitan Perintah Raw Binary ESC/POS (Backend)
Gunakan helper `escpos()` di PHP:
```php
use function escpos;

$escpos = escpos(cols: 32) // 32 untuk 58mm, 48 untuk 80mm
    ->initialize()
    ->align('center')
    ->doubleSize(true)
    ->bold(true)
    ->line("TOKO KITA SEJAHTERA")
    ->doubleSize(false)
    ->line("Jl. Pemuda No. 12, Surabaya")
    ->divider('=')
    ->align('left')
    ->row("Kopi Robusta 250g", "35.000")
    ->row("Teh Melati Celup", "15.000")
    ->divider('-')
    ->bold(true)
    ->row("TOTAL BELANJA", "Rp 50.000")
    ->bold(false)
    ->divider('=')
    ->align('center')
    ->line("Terima Kasih Atas Kunjungan Anda")
    ->feed(3)
    ->cut(partial: true);

// Dapatkan string Base64 untuk dikirim via JSON response
$base64Payload = $escpos->toBase64();
```

#### 2. Pencetakan Langsung via Web Bluetooth API (Frontend)
Gunakan utilitas `BluetoothPrinterClient` di React/TypeScript:
```typescript
import { BluetoothPrinterClient } from '@/utils/bluetoothPrinter';

async function handlePrintStruk(base64Payload: string) {
    const printer = new BluetoothPrinterClient();
    try {
        // Membuka dialog pemindaian Bluetooth browser
        const device = await printer.connect();
        console.log('Terhubung ke printer:', device.name);

        // Cetak data binary
        await printer.printBase64(base64Payload);
    } catch (err) {
        alert('Gagal mencetak ke printer bluetooth: ' + (err as Error).message);
    } finally {
        printer.disconnect();
    }
}
```

### B. Printer Kantor & Dokumen Resmi (A4 / Letter)
Untuk Surat Keputusan (SK) Yayasan, Faktur Tagihan, Berita Acara, dan Kuitansi Resmi:
- Gunakan `document('a4')` di backend yang menghasilkan halaman HTML dengan CSS `@page { size: A4; margin: 15mm; }`.
- Browser pengguna dapat langsung mencetak via dialog cetak standar (`window.print()`).
- Dilengkapi penempatan SVG QR Code (`qr_code()`) dan Barcode (`barcode()`) yang tajam pada resolusi cetak 300-600 DPI.

---

## 4. Integrasi Barcode & QR Code Scanner

### A. Hardware Barcode Scanner (Handheld USB / Bluetooth HID)
Hardware barcode scanner bertindak sebagai *Keyboard Wedge* yang mengetikkan karakter secara sangat cepat (<50ms per karakter) dan mengirimkan tombol `Enter` di akhir kode.

#### Frontend Hook: `useBarcodeScanner`
Hook ini mencegat input scanner di manapun fokus kursor berada:
```tsx
import { useBarcodeScanner } from '@/hooks/useBarcodeScanner';

export function PosCheckout() {
    useBarcodeScanner({
        onScan: (barcode) => {
            console.log('Barcode terdeteksi:', barcode);
            // Tambahkan item ke keranjang belanja / cari SKU
            addProductToCart(barcode);
        },
        minChars: 4,
        maxIntervalMs: 50, // Hanya merespons ketukan super cepat dari alat scanner
    });

    return <div>Arahkan scanner ke barcode produk...</div>;
}
```

### B. Kamera Smartphone sebagai Scanner
- Gunakan HTML5 `<input type="file" accept="image/*" capture="environment">` untuk membuka kamera belakang smartphone saat membutuhkan foto fisik kuitansi atau bukti pengiriman.
- Untuk pemindaian barcode langsung via kamera video stream, browser modern mendukung `BarcodeDetector API` native W3C.

---

## 5. Input Sentuh & Tanda Tangan Digital (`SignaturePad`)

Untuk bukti serah terima paket kurir, tanda terima setoran canvasser, atau presensi tanda tangan rapat yayasan:

```tsx
import { SignaturePad } from '@/Components/SignaturePad';

export function ProofOfDeliveryModal({ onComplete }: { onComplete: (signaturePng: string) => void }) {
    return (
        <SignaturePad
            width={380}
            height={180}
            penColor="#111827"
            label="Tanda tangan penerima barang di bawah ini:"
            onSave={(base64Png) => {
                // Kirim base64Png ke API Storage backend
                onComplete(base64Png);
            }}
            onClear={() => console.log('Tanda tangan dihapus')}
        />
    );
}
```

---

## 6. Sensor GPS & Pelacakan Lokasi Bergerak

Gunakan hook `useGeoTracker` untuk mengambil koordinat posisi akurat dari chip GPS smartphone:

```tsx
import { useGeoTracker } from '@/hooks/useGeoTracker';

export function DriverLocationWidget() {
    const { coords, isTracking, startTracking, stopTracking, error } = useGeoTracker({
        enableHighAccuracy: true,
        autoStart: true,
    });

    if (error) return <div>Peringatan: {error}</div>;

    return (
        <div>
            <div>Latitude: {coords?.latitude}</div>
            <div>Longitude: {coords?.longitude}</div>
            <div>Akurasi GPS: ±{coords?.accuracy} meter</div>
        </div>
    );
}
```
Koordinat tersebut kemudian dikirimkan ke backend untuk diproses menggunakan `Core\Geo\Geo` (misal: mencari outlet terdekat dalam radius 2 km via Haversine).

---

## 7. Progressive Web App (PWA) & Mode Offline

### Karakteristik PWA Platform
- **`public/manifest.json`**: Mengaktifkan mode tampilan `standalone` (menghilangkan address bar browser, terasa seperti native app).
- **`public/sw.js`**: Service Worker yang menyimpan *application shell* di cache perangkat.
- **Ketahanan Offline**: Ketika driver atau sales canvassing memasuki area *blank spot* (basement atau pedalaman), halaman aplikasi tetap dapat dibuka.

---

## 8. Persyaratan Keamanan & Izin Perangkat (Permissions)

Browser modern mewajibkan protokol keamanan ketat untuk mengakses perangkat keras:
1. **Koneksi HTTPS**: Wajib untuk Web Bluetooth API, Geolocation GPS, dan Service Worker PWA (kecuali saat pengujian di `localhost`).
2. **User Gesture Requirement**: Pemasangan Bluetooth atau pemicuan kamera harus diawali oleh interaksi pengguna (klik tombol), bukan dieksekusi otomatis di latar belakang.
3. **Graceful Degradation**: Jika pengguna menolak izin lokasi atau browser tidak mendukung Bluetooth, aplikasi tetap dapat beroperasi (misal: memilih outlet dari daftar manual atau mencetak via dialog browser standar).
