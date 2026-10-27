import { useEffect, useRef, useState } from 'react';

export interface BarcodeScannerOptions {
    onScan: (barcode: string) => void;
    minChars?: number;
    maxIntervalMs?: number;
    enabled?: boolean;
    ignoreInputFocus?: boolean;
}

/**
 * Hook global untuk mencegat input hardware barcode / QR scanner (USB/Bluetooth HID)
 * Scanner mengirim rentetan ketukan keyboard berkecepatan tinggi (<50ms per karakter) diakhiri Enter.
 */
export function useBarcodeScanner({
    onScan,
    minChars = 3,
    maxIntervalMs = 50,
    enabled = true,
    ignoreInputFocus = true,
}: BarcodeScannerOptions) {
    const [lastScan, setLastScan] = useState<string | null>(null);
    const bufferRef = useRef<string>('');
    const lastKeyTimeRef = useRef<number>(0);

    useEffect(() => {
        if (!enabled) return;

        const handleKeyDown = (event: KeyboardEvent) => {
            const target = event.target as HTMLElement | null;

            // Jika fokus di input atau textarea, abaikan kecuali disetel ignoreInputFocus = false
            if (ignoreInputFocus && target) {
                const tagName = target.tagName.toUpperCase();
                const isContentEditable = target.isContentEditable;
                if (tagName === 'INPUT' || tagName === 'TEXTAREA' || tagName === 'SELECT' || isContentEditable) {
                    return;
                }
            }

            const now = Date.now();
            const elapsed = now - lastKeyTimeRef.current;
            lastKeyTimeRef.current = now;

            // Jika jarak antar karakter terlalu lambat, reset buffer (karena ketikan manusia)
            if (elapsed > maxIntervalMs && bufferRef.current.length > 0) {
                bufferRef.current = '';
            }

            if (event.key === 'Enter') {
                if (bufferRef.current.length >= minChars) {
                    const scanned = bufferRef.current;
                    setLastScan(scanned);
                    onScan(scanned);
                    bufferRef.current = '';
                    event.preventDefault();
                }
            } else if (event.key.length === 1) {
                // Hanya tampung karakter printable tunggal
                bufferRef.current += event.key;
            }
        };

        window.addEventListener('keydown', handleKeyDown);
        return () => {
            window.removeEventListener('keydown', handleKeyDown);
        };
    }, [enabled, onScan, minChars, maxIntervalMs, ignoreInputFocus]);

    return { lastScan };
}
