<?php

declare(strict_types=1);

namespace Core\Middleware;

use Closure;
use Core\Container;
use Core\Http\Request;
use Core\Http\Response;

class ThrottleRequests
{
    private string $storageDir;

    public function __construct(?string $storageDir = null)
    {
        if ($storageDir !== null) {
            $this->storageDir = rtrim($storageDir, '/\\');
        } else {
            $baseStorage = dirname(__DIR__, 2) . '/storage/framework/ratelimit';
            $this->storageDir = is_dir(dirname(__DIR__, 2) . '/storage') ? $baseStorage : (sys_get_temp_dir() . '/aio_ratelimit');
        }

        if (!is_dir($this->storageDir)) {
            @mkdir($this->storageDir, 0777, true);
        }
    }

    /**
     * Factory closure untuk router: throttle:maxAttempts,decayMinutes
     */
    public static function with(int $maxAttempts, int $decayMinutes = 1, ?Container $container = null): Closure
    {
        return function (Request $request, callable $next) use ($maxAttempts, $decayMinutes, $container): Response {
            /** @var ThrottleRequests $instance */
            $c = $container ?? (function_exists('app') && app() instanceof Container ? app() : Container::getInstance());
            $instance = ($c && $c->has(self::class)) ? $c->get(self::class) : new self();
            return $instance->handle($request, $next, $maxAttempts, $decayMinutes);
        };
    }

    public function handle(Request $request, callable $next, int $maxAttempts = 60, int $decayMinutes = 1): Response
    {
        $maxAttempts = max(1, $maxAttempts);
        $decayMinutes = max(1, $decayMinutes);
        $decaySeconds = $decayMinutes * 60;
        $now = time();

        $key = $this->resolveRequestSignature($request);
        $filePath = $this->storageDir . '/' . $key . '.json';

        $hits = [];
        if (is_file($filePath)) {
            $content = @file_get_contents($filePath);
            if ($content) {
                $decoded = json_decode($content, true);
                if (is_array($decoded) && isset($decoded['hits']) && is_array($decoded['hits'])) {
                    $hits = $decoded['hits'];
                }
            }
        }

        // Filter hits dalam rolling window
        $windowStart = $now - $decaySeconds;
        $validHits = array_values(array_filter($hits, fn(int $timestamp) => $timestamp > $windowStart));

        if (count($validHits) >= $maxAttempts) {
            $oldestHit = $validHits[0];
            $retryAfter = max(1, ($oldestHit + $decaySeconds) - $now);

            $headers = [
                'X-RateLimit-Limit' => (string) $maxAttempts,
                'X-RateLimit-Remaining' => '0',
                'Retry-After' => (string) $retryAfter,
            ];

            $isJson = $request->isJson() || $request->wantsJson() || $request->isInertia();

            if ($isJson) {
                return Response::json([
                    'error' => 'Too Many Requests',
                    'message' => "Terlalu banyak permintaan. Silakan coba kembali dalam {$retryAfter} detik.",
                ], 429, $headers);
            }

            return new Response(
                "<!DOCTYPE html><html><head><title>429 Too Many Requests</title></head><body style='font-family:sans-serif;padding:2rem;background:#0f172a;color:#f87171'><h1>429 - Terlalu Banyak Permintaan</h1><p>Terlalu banyak permintaan. Silakan coba kembali dalam {$retryAfter} detik.</p></body></html>",
                429,
                array_merge(['Content-Type' => 'text/html; charset=UTF-8'], $headers)
            );
        }

        // Hit diperbolehkan
        $validHits[] = $now;
        @file_put_contents($filePath, json_encode(['hits' => $validHits]), LOCK_EX);

        $remaining = max(0, $maxAttempts - count($validHits));

        /** @var Response $response */
        $response = $next($request);

        $response->setHeader('X-RateLimit-Limit', (string) $maxAttempts);
        $response->setHeader('X-RateLimit-Remaining', (string) $remaining);

        return $response;
    }

    private function resolveRequestSignature(Request $request): string
    {
        $ip = $request->ip();
        $path = $request->path();
        return sha1("aio_rate_{$ip}_{$path}");
    }
}
