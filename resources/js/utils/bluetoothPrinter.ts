/**
 * Utilitas Web Bluetooth API untuk koneksi dan pencetakan langsung ke printer thermal portabel (58mm/80mm)
 * Mendukung printer bluetooth mini seperti Panda, Eppos, Xprinter, Sunmi, dan Zjiang.
 */

// UUID layanan printer bluetooth standar (SPP / Serial Port Profile)
const KNOWN_PRINTER_SERVICES = [
    '000018f0-0000-1000-8000-00805f9b34fb',
    'e7810a71-73ae-499d-8c15-faa9aef0c3f2',
    '49535343-fe7d-4ae5-8fa9-9fafd205e455',
];

export interface BluetoothPrinterDevice {
    id: string;
    name: string;
}

export class BluetoothPrinterClient {
    private device: any = null;
    private characteristic: any = null;

    /**
     * Periksa apakah browser saat ini mendukung Web Bluetooth API
     */
    public static isSupported(): boolean {
        return typeof window !== 'undefined' && 'bluetooth' in navigator;
    }

    /**
     * Buka modal pemindaian perangkat Bluetooth browser untuk memilih printer thermal
     */
    public async connect(): Promise<BluetoothPrinterDevice> {
        if (!BluetoothPrinterClient.isSupported()) {
            throw new Error('Web Bluetooth API tidak didukung di browser ini. Gunakan Chrome/Edge.');
        }

        const nav = navigator as any;
        this.device = await nav.bluetooth.requestDevice({
            acceptAllDevices: true,
            optionalServices: KNOWN_PRINTER_SERVICES,
        });

        if (!this.device || !this.device.gatt) {
            throw new Error('Gagal mendeteksi koneksi GATT printer');
        }

        const server = await this.device.gatt.connect();

        // Cari characteristic yang mendukung operasi penulisan data (write/writeWithoutResponse)
        for (const serviceUuid of KNOWN_PRINTER_SERVICES) {
            try {
                const service = await server.getPrimaryService(serviceUuid);
                const characteristics = await service.getCharacteristics();
                for (const char of characteristics) {
                    if (char.properties.write || char.properties.writeWithoutResponse) {
                        this.characteristic = char;
                        break;
                    }
                }
                if (this.characteristic) break;
            } catch {
                // Lanjut ke UUID service berikutnya jika tidak cocok
            }
        }

        return {
            id: this.device.id,
            name: this.device.name || 'Thermal Bluetooth Printer',
        };
    }

    /**
     * Kirim byte binary ESC/POS mentah (Uint8Array) ke printer
     */
    public async printBytes(data: Uint8Array): Promise<void> {
        if (!this.characteristic) {
            throw new Error('Printer belum terhubung. Jalankan connect() terlebih dahulu.');
        }

        // Kirim dalam chunk 512 bytes untuk mencegah buffer overflow pada chip bluetooth printer murah
        const CHUNK_SIZE = 512;
        for (let i = 0; i < data.length; i += CHUNK_SIZE) {
            const chunk = data.slice(i, i + CHUNK_SIZE);
            if (this.characteristic.writeValueWithoutResponse) {
                await this.characteristic.writeValueWithoutResponse(chunk);
            } else {
                await this.characteristic.writeValue(chunk);
            }
        }
    }

    /**
     * Cetak data string Base64 yang dihasilkan oleh backend escpos()->toBase64()
     */
    public async printBase64(base64String: string): Promise<void> {
        const binaryString = atob(base64String);
        const len = binaryString.length;
        const bytes = new Uint8Array(len);
        for (let i = 0; i < len; i++) {
            bytes[i] = binaryString.charCodeAt(i);
        }
        await this.printBytes(bytes);
    }

    /**
     * Putuskan koneksi Bluetooth
     */
    public disconnect(): void {
        if (this.device && this.device.gatt && this.device.gatt.connected) {
            this.device.gatt.disconnect();
        }
        this.device = null;
        this.characteristic = null;
    }
}
