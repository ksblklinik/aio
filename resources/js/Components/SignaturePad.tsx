import React, { useRef, useState, useEffect, useCallback } from 'react';

export interface SignaturePadProps {
    onSave?: (base64Png: string) => void;
    onClear?: () => void;
    width?: number;
    height?: number;
    penColor?: string;
    backgroundColor?: string;
    label?: string;
    className?: string;
}

/**
 * Komponen Canvas tanda tangan digital ramah sentuhan (Touch, Stylus, Mouse)
 * Sangat cocok untuk bukti serah terima order pengiriman, canvassing, dan presensi rapat yayasan.
 */
export const SignaturePad: React.FC<SignaturePadProps> = ({
    onSave,
    onClear,
    width = 400,
    height = 180,
    penColor = '#1f2937',
    backgroundColor = '#ffffff',
    label = 'Goreskan tanda tangan pada kotak di bawah ini',
    className = '',
}) => {
    const canvasRef = useRef<HTMLCanvasElement | null>(null);
    const [isDrawing, setIsDrawing] = useState(false);
    const [hasDrawn, setHasDrawn] = useState(false);

    // Inisialisasi canvas background
    const initCanvas = useCallback(() => {
        const canvas = canvasRef.current;
        if (!canvas) return;
        const ctx = canvas.getContext('2d');
        if (!ctx) return;

        ctx.fillStyle = backgroundColor;
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        setHasDrawn(false);
    }, [backgroundColor]);

    useEffect(() => {
        initCanvas();
    }, [initCanvas]);

    const getCanvasCoordinates = (e: React.MouseEvent | React.TouchEvent) => {
        const canvas = canvasRef.current;
        if (!canvas) return { x: 0, y: 0 };
        const rect = canvas.getBoundingClientRect();

        let clientX = 0;
        let clientY = 0;

        if ('touches' in e && e.touches.length > 0) {
            clientX = e.touches[0].clientX;
            clientY = e.touches[0].clientY;
        } else if ('clientX' in e) {
            clientX = e.clientX;
            clientY = e.clientY;
        }

        return {
            x: clientX - rect.left,
            y: clientY - rect.top,
        };
    };

    const startDrawing = (e: React.MouseEvent | React.TouchEvent) => {
        const canvas = canvasRef.current;
        if (!canvas) return;
        const ctx = canvas.getContext('2d');
        if (!ctx) return;

        const { x, y } = getCanvasCoordinates(e);
        ctx.beginPath();
        ctx.moveTo(x, y);
        ctx.lineWidth = 2.5;
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';
        ctx.strokeStyle = penColor;

        setIsDrawing(true);
        setHasDrawn(true);
    };

    const draw = (e: React.MouseEvent | React.TouchEvent) => {
        if (!isDrawing) return;
        const canvas = canvasRef.current;
        if (!canvas) return;
        const ctx = canvas.getContext('2d');
        if (!ctx) return;

        const { x, y } = getCanvasCoordinates(e);
        ctx.lineTo(x, y);
        ctx.stroke();

        // Prevent scrolling while drawing on mobile
        if ('touches' in e) {
            e.preventDefault();
        }
    };

    const stopDrawing = () => {
        setIsDrawing(false);
    };

    const handleClear = () => {
        initCanvas();
        if (onClear) onClear();
    };

    const handleSave = () => {
        const canvas = canvasRef.current;
        if (!canvas || !hasDrawn) return;
        const base64 = canvas.toDataURL('image/png');
        if (onSave) onSave(base64);
    };

    return (
        <div className={`flex flex-col gap-2 ${className}`}>
            {label && (
                <div className="text-xs font-medium text-slate-500 dark:text-slate-400">
                    {label}
                </div>
            )}
            <div className="relative border-2 border-dashed border-slate-300 dark:border-slate-700 rounded-lg overflow-hidden touch-none w-fit bg-white">
                <canvas
                    ref={canvasRef}
                    width={width}
                    height={height}
                    onMouseDown={startDrawing}
                    onMouseMove={draw}
                    onMouseUp={stopDrawing}
                    onMouseLeave={stopDrawing}
                    onTouchStart={startDrawing}
                    onTouchMove={draw}
                    onTouchEnd={stopDrawing}
                    className="cursor-crosshair block"
                />
            </div>
            <div className="flex gap-2 items-center">
                <button
                    type="button"
                    onClick={handleClear}
                    className="px-3 py-1.5 text-xs font-semibold rounded bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 transition-colors"
                >
                    Hapus Ulang
                </button>
                {onSave && (
                    <button
                        type="button"
                        onClick={handleSave}
                        disabled={!hasDrawn}
                        className="px-3 py-1.5 text-xs font-semibold rounded bg-brand-primary hover:bg-brand-primary/90 text-white disabled:opacity-50 transition-colors"
                    >
                        Simpan Tanda Tangan
                    </button>
                )}
            </div>
        </div>
    );
};
