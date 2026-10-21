<?php

declare(strict_types=1);

namespace App\Modules\Modules\Middleware;

use App\Modules\Auth\Services\AuthService;
use Core\Http\Request;
use Core\Http\Response;
use Core\Http\Session;
use Core\Modules\ModuleManager;
use Closure;

class EnsureModuleActiveForTenant
{
    public function __construct(
        private AuthService $auth,
        private ModuleManager $moduleManager,
        private Session $session
    ) {
    }

    /**
     * Factory helper untuk mendaftarkan middleware dengan target moduleId spesifik
     */
    public static function module(string $moduleId): Closure
    {
        return function (Request $request, callable $next) use ($moduleId): Response {
            /** @var EnsureModuleActiveForTenant $instance */
            $instance = app(EnsureModuleActiveForTenant::class);
            return $instance->handleWithModule($request, $next, $moduleId);
        };
    }

    public function handle(Request $request, callable $next, ?string $moduleId = null): Response
    {
        $targetModule = $moduleId ?? $this->resolveModuleFromRequest($request);
        if ($targetModule === null) {
            return $next($request);
        }

        return $this->handleWithModule($request, $next, $targetModule);
    }

    public function handleWithModule(Request $request, callable $next, string $moduleId): Response
    {
        $tenant = $this->auth->currentTenant();

        // Jika tidak ada konteks tenant aktif, biarkan middleware Auth yang menangani
        if (!$tenant) {
            return $next($request);
        }

        $isActive = $this->moduleManager->isModuleActiveForTenant((int) $tenant->id, $moduleId);

        if (!$isActive) {
            $isJsonRequest = $request->isJson() || str_contains((string) $request->header('accept', ''), 'application/json');
            if ($isJsonRequest && !$request->isInertia()) {
                return Response::json([
                    'error' => 'Module Inactive',
                    'message' => "Modul [{$moduleId}] dinonaktifkan untuk workspace ini.",
                ], 403);
            }

            $this->session->flash('error', "Akses ditolak: Modul [{$moduleId}] dinonaktifkan untuk workspace '{$tenant->name}'.");
            return Response::redirect('/dashboard');
        }

        return $next($request);
    }

    private function resolveModuleFromRequest(Request $request): ?string
    {
        $path = trim($request->path(), '/');
        $segments = explode('/', $path);
        return $segments[0] ?? null;
    }
}
