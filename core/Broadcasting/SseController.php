<?php

declare(strict_types=1);

namespace Core\Broadcasting;

use Core\Database\TenantContext;
use Core\Http\Request;
use Core\Http\Response;

class SseController
{
    public function __construct(
        protected SseBroadcasterInterface $broadcaster,
        protected ?TenantContext $tenantContext = null
    ) {}

    /**
     * Endpoint aliran Server-Sent Events (SSE) native HTTP/1.1 & HTTP/2.
     * GET /api/sse/stream?channel={channel}&last_event_id={id}&tenant_id={id}
     */
    public function stream(Request $request): Response
    {
        $channel = (string)($request->query('channel') ?: 'global');
        
        // Baca ID event terakhir dari header resmi Last-Event-ID atau query string
        $lastEventId = $request->header('Last-Event-ID') ?: $request->query('last_event_id');
        $afterId = ($lastEventId !== null && is_numeric($lastEventId)) ? (int)$lastEventId : null;

        $tenantId = $request->query('tenant_id');
        if ($tenantId === null && $this->tenantContext !== null) {
            $tenantId = $this->tenantContext->getTenantId();
        }

        return Response::sse(function ($sender) use ($channel, $afterId, $tenantId) {
            $currentAfterId = $afterId;

            // Ping pembuka untuk konfirmasi koneksi streaming aktif
            echo ": ping\n\n";
            if (php_sapi_name() !== 'cli') {
                if (function_exists('ob_flush') && ob_get_level() > 0) {
                    @ob_flush();
                }
                flush();
            }

            // Durasi maksimal stream per request (25 detik) untuk mencegah worker starvation
            // Di lingkungan testing / CLI, batasi 1 iterasi saja
            $isTesting = defined('PHPUNIT_RUNNING') || php_sapi_name() === 'cli';
            $maxSeconds = $isTesting ? 1 : 25;
            $startTime = time();
            $lastPing = time();

            while ((time() - $startTime) < $maxSeconds) {
                if (function_exists('connection_aborted') && connection_aborted()) {
                    break;
                }

                $events = $this->broadcaster->getEvents($channel, $currentAfterId, $tenantId, 50);
                if (!empty($events)) {
                    foreach ($events as $event) {
                        $sender($event['payload'], $event['event'], (string)$event['id']);
                        $currentAfterId = max((int)$currentAfterId, (int)$event['id']);
                    }
                }

                // Heartbeat ping berkala setiap 5 detik
                if ((time() - $lastPing) >= 5) {
                    echo ": ping\n\n";
                    if (php_sapi_name() !== 'cli') {
                        if (function_exists('ob_flush') && ob_get_level() > 0) {
                            @ob_flush();
                        }
                        flush();
                    }
                    $lastPing = time();
                }

                if ($isTesting) {
                    break;
                }

                // Sleep 300ms untuk responsivitas sub-detik
                usleep(300000);
            }
        });
    }

    /**
     * Endpoint fallback polling berbasis JSON biasa.
     * GET /api/sse/poll?channel={channel}&after_id={id}&tenant_id={id}
     */
    public function poll(Request $request): Response
    {
        $channel = (string)($request->query('channel') ?: 'global');
        
        $afterIdParam = $request->query('after_id') ?: $request->query('last_event_id');
        $afterId = ($afterIdParam !== null && is_numeric($afterIdParam)) ? (int)$afterIdParam : null;

        $tenantId = $request->query('tenant_id');
        if ($tenantId === null && $this->tenantContext !== null) {
            $tenantId = $this->tenantContext->getTenantId();
        }

        $limit = is_numeric($request->query('limit')) ? (int)$request->query('limit') : 50;
        $events = $this->broadcaster->getEvents($channel, $afterId, $tenantId, $limit);

        $lastId = !empty($events) ? (int)end($events)['id'] : $afterId;

        return Response::json([
            'status' => 'success',
            'channel' => $channel,
            'events' => $events,
            'last_id' => $lastId,
            'count' => count($events),
        ]);
    }
}
