import { useEffect, useRef, useState, useCallback } from 'react';

export interface SseEvent<T = any> {
  id?: number | string;
  event: string;
  data: T;
  timestamp?: string;
}

export interface UseSseOptions<T = any> {
  channel: string;
  tenantId?: number | string | null;
  events?: string[]; // Daftar nama event spesifik untuk didengarkan (default: all)
  enabled?: boolean;
  onEvent?: (event: SseEvent<T>) => void;
  fallbackPollIntervalMs?: number; // Durasi polling darurat jika SSE terputus (default: 5000ms)
}

export function useSse<T = any>({
  channel,
  tenantId,
  events = [],
  enabled = true,
  onEvent,
  fallbackPollIntervalMs = 5000,
}: UseSseOptions<T>) {
  const [isConnected, setIsConnected] = useState(false);
  const [lastEvent, setLastEvent] = useState<SseEvent<T> | null>(null);
  const [connectionError, setConnectionError] = useState<boolean>(false);

  const lastEventIdRef = useRef<number | string | null>(null);
  const eventSourceRef = useRef<EventSource | null>(null);
  const pollTimerRef = useRef<ReturnType<typeof setInterval> | null>(null);
  const onEventRef = useRef(onEvent);
  onEventRef.current = onEvent;

  const handleIncomingData = useCallback((eventName: string, rawData: string, id?: string) => {
    try {
      let parsed: T;
      try {
        parsed = JSON.parse(rawData);
      } catch {
        parsed = rawData as unknown as T;
      }

      if (id) {
        lastEventIdRef.current = id;
      }

      const evt: SseEvent<T> = {
        id,
        event: eventName,
        data: parsed,
        timestamp: new Date().toISOString(),
      };

      setLastEvent(evt);
      if (onEventRef.current) {
        onEventRef.current(evt);
      }
    } catch (err) {
      console.error('[SSE] Failed to process event data:', err);
    }
  }, []);

  useEffect(() => {
    if (!enabled || typeof window === 'undefined') {
      return;
    }

    let isMounted = true;

    // Cek ketersediaan EventSource di browser
    const supportsSse = 'EventSource' in window;

    const startPollingFallback = () => {
      if (pollTimerRef.current) {
        clearInterval(pollTimerRef.current);
      }

      pollTimerRef.current = setInterval(async () => {
        try {
          const params = new URLSearchParams({ channel });
          if (tenantId) params.append('tenant_id', String(tenantId));
          if (lastEventIdRef.current) params.append('after_id', String(lastEventIdRef.current));

          const res = await fetch(`/api/sse/poll?${params.toString()}`);
          if (!res.ok) return;

          const json = await res.json();
          if (json.status === 'success' && Array.isArray(json.events)) {
            json.events.forEach((item: any) => {
              handleIncomingData(item.event, JSON.stringify(item.payload), String(item.id));
            });
            if (json.last_id) {
              lastEventIdRef.current = json.last_id;
            }
          }
        } catch {
          // Silent polling error
        }
      }, fallbackPollIntervalMs);
    };

    if (supportsSse) {
      try {
        const params = new URLSearchParams({ channel });
        if (tenantId) params.append('tenant_id', String(tenantId));
        if (lastEventIdRef.current) params.append('last_event_id', String(lastEventIdRef.current));

        const es = new EventSource(`/api/sse/stream?${params.toString()}`);
        eventSourceRef.current = es;

        es.onopen = () => {
          if (!isMounted) return;
          setIsConnected(true);
          setConnectionError(false);
          if (pollTimerRef.current) {
            clearInterval(pollTimerRef.current);
            pollTimerRef.current = null;
          }
        };

        es.onerror = () => {
          if (!isMounted) return;
          setIsConnected(false);
          setConnectionError(true);
          // Jika koneksi SSE gagal atau putus, nyalakan fallback polling otomatis
          startPollingFallback();
        };

        // Standard message listener
        es.onmessage = (e) => {
          if (!isMounted) return;
          handleIncomingData('message', e.data, e.lastEventId);
        };

        // Custom event listeners
        events.forEach((evtName) => {
          es.addEventListener(evtName, (e: any) => {
            if (!isMounted) return;
            handleIncomingData(evtName, e.data, e.lastEventId);
          });
        });
      } catch (err) {
        console.warn('[SSE] EventSource init failed, activating polling fallback:', err);
        setConnectionError(true);
        startPollingFallback();
      }
    } else {
      // Browser tidak mendukung EventSource, langsung gunakan polling
      startPollingFallback();
    }

    return () => {
      isMounted = false;
      if (eventSourceRef.current) {
        eventSourceRef.current.close();
        eventSourceRef.current = null;
      }
      if (pollTimerRef.current) {
        clearInterval(pollTimerRef.current);
        pollTimerRef.current = null;
      }
      setIsConnected(false);
    };
  }, [channel, tenantId, enabled, events, fallbackPollIntervalMs, handleIncomingData]);

  return {
    isConnected,
    connectionError,
    lastEvent,
    lastEventId: lastEventIdRef.current,
  };
}
