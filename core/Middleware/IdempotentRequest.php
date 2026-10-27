<?php

declare(strict_types=1);

namespace Core\Middleware;

use Core\Database\TenantContext;
use Core\Http\Request;
use Core\Http\Response;

class IdempotentRequest
{
    public function __construct(
        private ?string $storagePath = null,
        private ?TenantContext $context = null
    ) {
        $this->storagePath = $storagePath ?? (function_exists('storage_path') ? storage_path('framework/idempotency') : dirname(__DIR__, 2) . '/storage/framework/idempotency');
        $this->context = $context ?? TenantContext::getInstance();

        if (!is_dir($this->storagePath)) {
            @mkdir($this->storagePath, 0755, true);
        }
    }

    /**
     * @param callable(Request): Response $next
     */
    public function handle(Request $request, callable $next, int $ttlSeconds = 86400): Response
    {
        // Hanya terapkan proteksi idempotency pada method mutasi (POST, PUT, PATCH, DELETE)
        if (in_array(strtoupper($request->method()), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        $idempotencyKey = $request->header('X-Idempotency-Key') ?? $request->header('Idempotency-Key');
        if ($idempotencyKey === null || trim((string) $idempotencyKey) === '') {
            return $next($request);
        }

        $cleanKey = trim((string) $idempotencyKey);
        $tenantId = $this->context->getTenantId() ?? '0';
        $cacheFile = rtrim($this->storagePath, '/\\') . '/' . hash('sha256', "{$tenantId}:{$cleanKey}") . '.json';
        $now = time();

        // 1. Cek apakah ada respon tersimpan
        if (file_exists($cacheFile)) {
            $data = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($data) && ($data['expires_at'] ?? 0) > $now) {
                if (($data['status_state'] ?? '') === 'in_progress') {
                    // Jika baru dibuat kurang dari 30 detik lalu, cegah eksekusi paralel
                    if ($now - ($data['created_at'] ?? 0) < 30) {
                        return Response::json([
                            'error' => 'Conflict',
                            'message' => 'Permintaan dengan Idempotency-Key ini sedang diproses. Silakan tunggu.',
                        ], 409);
                    }
                } elseif (($data['status_state'] ?? '') === 'completed') {
                    $cachedHeaders = (array) ($data['headers'] ?? []);
                    $cachedHeaders['X-Cache-Lookup'] = 'HIT-IDEMPOTENT';
                    return new Response(
                        (string) ($data['body'] ?? ''),
                        (int) ($data['status_code'] ?? 200),
                        $cachedHeaders
                    );
                }
            }
        }

        // 2. Kunci status in_progress
        $inProgressPayload = [
            'status_state' => 'in_progress',
            'created_at' => $now,
            'expires_at' => $now + $ttlSeconds,
        ];
        @file_put_contents($cacheFile, json_encode($inProgressPayload), LOCK_EX);

        try {
            // 3. Eksekusi handler request sesungguhnya
            $response = $next($request);

            // 4. Simpan hasil respon jika bukan server error (5xx)
            if ($response->getStatusCode() < 500) {
                $completedPayload = [
                    'status_state' => 'completed',
                    'status_code' => $response->getStatusCode(),
                    'headers' => $response->getHeaders(),
                    'body' => $response->getBody(),
                    'created_at' => $now,
                    'expires_at' => $now + $ttlSeconds,
                ];
                @file_put_contents($cacheFile, json_encode($completedPayload), LOCK_EX);
            } else {
                @unlink($cacheFile);
            }

            return $response;
        } catch (\Throwable $e) {
            @unlink($cacheFile);
            throw $e;
        }
    }
}
