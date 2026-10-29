<?php

declare(strict_types=1);

namespace Core\Middleware;

use Closure;
use Core\Auth\AuthServiceInterface;
use Core\Auth\Gate;
use Core\Http\Request;
use Core\Http\Response;

class AuthorizePermission
{
    public function __construct(
        private Gate $gate,
        private ?AuthServiceInterface $auth = null
    ) {
    }

    /**
     * Factory closure untuk routing
     */
    public static function can(string $permission, ?\Core\Container $container = null): Closure
    {
        return function (Request $request, callable $next) use ($permission, $container): Response {
            /** @var AuthorizePermission $instance */
            $c = $container ?? (function_exists('app') && app() instanceof \Core\Container ? app() : \Core\Container::getInstance());
            $instance = $c->get(AuthorizePermission::class);
            return $instance->handleWithPermission($request, $next, $permission);
        };
    }

    public function handle(Request $request, callable $next, string $permission = ''): Response
    {
        return $this->handleWithPermission($request, $next, $permission);
    }

    public function handleWithPermission(Request $request, callable $next, string $permission): Response
    {
        $user = $this->auth?->user();
        $tenant = $this->auth?->currentTenant();

        if (!$user) {
            return Response::redirect('/login');
        }

        $tenantId = $tenant ? (int) $tenant->id : null;

        if ($this->gate->denies($user, $permission, $tenantId)) {
            $isJson = $request->isJson() || str_contains((string) $request->header('accept', ''), 'application/json');
            if ($isJson && !$request->isInertia()) {
                return Response::json([
                    'error' => 'Forbidden',
                    'message' => "Aksi tidak diizinkan: Anda tidak memiliki izin '{$permission}'.",
                ], 403);
            }

            return new Response(
                "<!DOCTYPE html><html><body style='font-family:sans-serif;padding:2rem;background:#0f172a;color:#f87171'><h1>403 - Akses Ditolak</h1><p>Anda tidak memiliki izin '{$permission}' untuk melakukan tindakan ini.</p><a href='/dashboard' style='color:#38bdf8'>Kembali ke Dashboard</a></body></html>",
                403,
                ['content-type' => 'text/html; charset=utf-8']
            );
        }

        return $next($request);
    }
}
