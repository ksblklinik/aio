<?php

declare(strict_types=1);

if (!function_exists('app')) {
    /**
     * Helper global untuk mengakses container atau instansiasi kelas
     */
    function app(?string $abstract = null): mixed
    {
        $container = \Core\Container::getInstance();
        if ($abstract === null) {
            return $container;
        }
        return $container->get($abstract);
    }
}

if (!function_exists('inertia')) {
    /**
     * Helper global untuk merender respons Inertia.js
     */
    function inertia(string $component, array $props = []): \Core\Http\Response
    {
        return \Core\Inertia\Inertia::getInstance()->render($component, $props);
    }
}

if (!function_exists('vite_asset')) {
    /**
     * Helper untuk memuat script/link Vite (mendukung HMR dev & production build manifest)
     */
    function vite_asset(string $entry = 'resources/js/app.tsx'): string
    {
        $hotFile = dirname(__DIR__) . '/public/hot';
        $manifestFile = dirname(__DIR__) . '/public/build/manifest.json';

        // 1. Cek apakah dev server Vite sedang berjalan (file public/hot atau default localhost:5173)
        $isDev = false;
        $viteHost = 'http://localhost:5173';

        if (file_exists($hotFile)) {
            $isDev = true;
            $viteHost = trim((string) file_get_contents($hotFile));
        } else {
            // Cek koneksi port dev server secara non-blocking
            $connection = @fsockopen('127.0.0.1', 5173, $errno, $errstr, 0.1);
            if (!is_resource($connection)) {
                $connection = @fsockopen('localhost', 5173, $errno, $errstr, 0.1);
            }
            if (is_resource($connection)) {
                $isDev = true;
                fclose($connection);
            }
        }

        if ($isDev) {
            return <<<HTML
<script type="module">
    import RefreshRuntime from '{$viteHost}/@react-refresh'
    RefreshRuntime.injectIntoGlobalHook(window)
    window.\$RefreshReg$ = () => {}
    window.\$RefreshSig$ = () => (type) => type
    window.__vite_plugin_react_preamble_installed__ = true
</script>
<script type="module" src="{$viteHost}/@vite/client"></script>
<script type="module" src="{$viteHost}/{$entry}"></script>
HTML;
        }

        // 2. Production build dari manifest
        if (file_exists($manifestFile)) {
            $manifest = json_decode((string) file_get_contents($manifestFile), true);
            if (isset($manifest[$entry])) {
                $item = $manifest[$entry];
                $tags = '';
                if (!empty($item['css'])) {
                    foreach ($item['css'] as $cssFile) {
                        $tags .= "<link rel=\"stylesheet\" href=\"/build/{$cssFile}\">\n";
                    }
                }
                $tags .= "<script type=\"module\" src=\"/build/{$item['file']}\"></script>";
                return $tags;
            }
        }

        return "<!-- Vite asset [{$entry}] not found -->";
    }
}

if (!function_exists('event')) {
    /**
     * Helper global untuk memancarkan Domain Event ke EventDispatcher.
     */
    function event(object $event): void
    {
        $dispatcher = app(\Core\Events\EventDispatcher::class);
        if ($dispatcher instanceof \Core\Events\EventDispatcher) {
            $dispatcher->dispatch($event);
        }
    }
}

if (!function_exists('env')) {
    /**
     * Helper global untuk mengakses variabel environment
     */
    function env(string $key, mixed $default = null): mixed
    {
        return \Core\Config\Env::get($key, $default);
    }
}

if (!function_exists('config')) {
    /**
     * Helper global untuk mengakses item konfigurasi (dot notation) atau instance Config
     */
    function config(?string $key = null, mixed $default = null): mixed
    {
        $instance = \Core\Config\Config::getInstance();
        if ($key === null) {
            return $instance;
        }
        return $instance->get($key, $default);
    }
}

if (!function_exists('logger')) {
    /**
     * Helper global untuk logging PSR-3
     */
    function logger(): \Core\Log\Logger
    {
        return \Core\Log\Logger::getInstance();
    }
}

if (!function_exists('can')) {
    /**
     * Helper global untuk mengevaluasi hak akses/izin user aktif pada tenant aktif
     */
    function can(string $permission): bool
    {
        try {
            $gate = app(\Core\Auth\Gate::class);
            $auth = app(\App\Modules\Auth\Services\AuthService::class);
            if (!$gate instanceof \Core\Auth\Gate || !$auth instanceof \App\Modules\Auth\Services\AuthService) {
                return false;
            }

            $user = $auth->user();
            $tenant = $auth->currentTenant();
            if (!$user || !$tenant) {
                return false;
            }

            return $gate->allows($user, $permission, (int) $tenant->id);
        } catch (\Throwable) {
            return false;
        }
    }
}



