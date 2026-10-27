import { useEffect, useRef, useState, useCallback } from 'react';

export interface GeoLocationCoords {
    latitude: number;
    longitude: number;
    accuracy: number;
    speed: number | null;
    heading: number | null;
    timestamp: number;
}

export interface UseGeoTrackerOptions {
    enableHighAccuracy?: boolean;
    timeout?: number;
    maximumAge?: number;
    autoStart?: boolean;
}

/**
 * Hook untuk pelacakan koordinat GPS perangkat mobile/tablet secara akurat
 */
export function useGeoTracker(options: UseGeoTrackerOptions = {}) {
    const {
        enableHighAccuracy = true,
        timeout = 15000,
        maximumAge = 0,
        autoStart = false,
    } = options;

    const [coords, setCoords] = useState<GeoLocationCoords | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [isTracking, setIsTracking] = useState<boolean>(false);
    const watchIdRef = useRef<number | null>(null);

    const isSupported = typeof window !== 'undefined' && 'geolocation' in navigator;

    const handleSuccess = useCallback((pos: GeolocationPosition) => {
        setCoords({
            latitude: pos.coords.latitude,
            longitude: pos.coords.longitude,
            accuracy: pos.coords.accuracy,
            speed: pos.coords.speed,
            heading: pos.coords.heading,
            timestamp: pos.timestamp,
        });
        setError(null);
    }, []);

    const handleError = useCallback((err: GeolocationPositionError) => {
        let msg = 'Gagal mengambil lokasi GPS';
        switch (err.code) {
            case err.PERMISSION_DENIED:
                msg = 'Izin akses lokasi ditolak oleh pengguna';
                break;
            case err.POSITION_UNAVAILABLE:
                msg = 'Sinyal GPS tidak tersedia atau perangkat di luar jangkauan';
                break;
            case err.TIMEOUT:
                msg = 'Waktu permintaan koordinat GPS habis (timeout)';
                break;
        }
        setError(msg);
    }, []);

    const getCurrentPosition = useCallback((): Promise<GeoLocationCoords> => {
        return new Promise((resolve, reject) => {
            if (!isSupported) {
                const err = 'Geolocation tidak didukung oleh browser ini';
                setError(err);
                reject(new Error(err));
                return;
            }

            navigator.geolocation.getCurrentPosition(
                (pos) => {
                    handleSuccess(pos);
                    resolve({
                        latitude: pos.coords.latitude,
                        longitude: pos.coords.longitude,
                        accuracy: pos.coords.accuracy,
                        speed: pos.coords.speed,
                        heading: pos.coords.heading,
                        timestamp: pos.timestamp,
                    });
                },
                (err) => {
                    handleError(err);
                    reject(err);
                },
                { enableHighAccuracy, timeout, maximumAge }
            );
        });
    }, [isSupported, enableHighAccuracy, timeout, maximumAge, handleSuccess, handleError]);

    const startTracking = useCallback(() => {
        if (!isSupported || watchIdRef.current !== null) return;

        setIsTracking(true);
        watchIdRef.current = navigator.geolocation.watchPosition(
            handleSuccess,
            handleError,
            { enableHighAccuracy, timeout, maximumAge }
        );
    }, [isSupported, enableHighAccuracy, timeout, maximumAge, handleSuccess, handleError]);

    const stopTracking = useCallback(() => {
        if (watchIdRef.current !== null) {
            navigator.geolocation.clearWatch(watchIdRef.current);
            watchIdRef.current = null;
        }
        setIsTracking(false);
    }, []);

    useEffect(() => {
        if (autoStart) {
            startTracking();
        }
        return () => {
            stopTracking();
        };
    }, [autoStart, startTracking, stopTracking]);

    return {
        coords,
        error,
        isTracking,
        isSupported,
        getCurrentPosition,
        startTracking,
        stopTracking,
    };
}
