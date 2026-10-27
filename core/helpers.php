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

if (!function_exists('storage')) {
    /**
     * Helper global untuk mengakses StorageManager terisolasi tenant
     */
    function storage(?int $tenantId = null): \Core\Storage\StorageManager
    {
        /** @var \Core\Storage\StorageManager $manager */
        $manager = app(\Core\Storage\StorageManager::class);
        return $tenantId !== null ? $manager->forTenant($tenantId) : $manager;
    }
}

if (!function_exists('setting')) {
    /**
     * Helper global untuk membaca setting konfigurasi dinamis tenant
     */
    function setting(string $key, mixed $default = null, ?int $tenantId = null): mixed
    {
        try {
            /** @var \Core\Settings\TenantSettings $settings */
            $settings = app(\Core\Settings\TenantSettings::class);
            return $settings->get($key, $default, $tenantId);
        } catch (\Throwable) {
            return $default;
        }
    }
}

if (!function_exists('format_currency')) {
    /**
     * Helper universal untuk memformat mata uang sesuai konfigurasi tenant
     */
    function format_currency(float|int $amount, ?string $currency = null, ?string $locale = null, ?int $tenantId = null): string
    {
        try {
            /** @var \Core\Settings\TenantSettings $settings */
            $settings = app(\Core\Settings\TenantSettings::class);
            $profile = $settings->getTenantProfile($tenantId);
            $currency = $currency ?? (string) ($profile['currency'] ?? 'USD');
            $locale = $locale ?? (string) ($profile['locale'] ?? 'en_US');
        } catch (\Throwable) {
            $currency = $currency ?? 'USD';
            $locale = $locale ?? 'en_US';
        }

        $upperCurr = strtoupper($currency);

        if (class_exists(\NumberFormatter::class)) {
            try {
                $formatter = new \NumberFormatter($locale, \NumberFormatter::CURRENCY);
                $formatted = $formatter->formatCurrency((float) $amount, $upperCurr);
                if ($formatted !== false) {
                    return $formatted;
                }
            } catch (\Throwable) {
                // Fallback to manual formatting below
            }
        }

        // Standard robust offline fallbacks
        return match ($upperCurr) {
            'IDR' => 'Rp ' . number_format($amount, 0, ',', '.'),
            'USD' => '$' . number_format($amount, 2, '.', ','),
            'EUR' => '€' . number_format($amount, 2, ',', '.'),
            'GBP' => '£' . number_format($amount, 2, '.', ','),
            default => $upperCurr . ' ' . number_format($amount, 2, '.', ','),
        };
    }
}

if (!function_exists('money')) {
    /**
     * Alias singkat format_currency
     */
    function money(float|int $amount, ?string $currency = null): string
    {
        return format_currency($amount, $currency);
    }
}

if (!function_exists('format_date_local')) {
    /**
     * Helper universal untuk memformat tanggal dan waktu sesuai timezone tenant
     */
    function format_date_local(string|\DateTimeInterface $date, string $format = 'Y-m-d H:i:s', ?string $timezone = null, ?int $tenantId = null): string
    {
        try {
            if ($timezone === null) {
                /** @var \Core\Settings\TenantSettings $settings */
                $settings = app(\Core\Settings\TenantSettings::class);
                $profile = $settings->getTenantProfile($tenantId);
                $timezone = (string) ($profile['timezone'] ?? 'UTC');
            }
        } catch (\Throwable) {
            $timezone = $timezone ?? 'UTC';
        }

        try {
            $tz = new \DateTimeZone($timezone);
        } catch (\Throwable) {
            $tz = new \DateTimeZone('UTC');
        }

        if ($date instanceof \DateTimeInterface) {
            $dt = \DateTimeImmutable::createFromInterface($date)->setTimezone($tz);
        } else {
            try {
                $dt = (new \DateTimeImmutable($date, new \DateTimeZone('UTC')))->setTimezone($tz);
            } catch (\Throwable) {
                return (string) $date;
            }
        }

        return $dt->format($format);
    }
}

if (!function_exists('sequence')) {
    /**
     * Helper global untuk menghasilkan penomoran dokumen atomik (anti-tabrakan)
     */
    function sequence(
        string $name,
        ?string $prefix = null,
        int $padding = 5,
        string $resetPeriod = 'never',
        ?string $formatPattern = null,
        ?int $tenantId = null
    ): string {
        /** @var \Core\Sequence\SequenceManager $manager */
        $manager = app(\Core\Sequence\SequenceManager::class);
        return $manager->next($name, $prefix, $padding, $resetPeriod, $formatPattern, $tenantId);
    }
}

if (!function_exists('mailer')) {
    /**
     * Helper global untuk mengakses layanan Mailer universal
     */
    function mailer(): \Core\Mail\Mailer
    {
        return app(\Core\Mail\Mailer::class);
    }
}

if (!function_exists('audit')) {
    /**
     * Helper global untuk logging audit aktivitas atau mengakses instance AuditLogger
     *
     * @param string|null $action Contoh: 'project.created', 'auth.login'
     * @param string|null $description Ringkasan manusiawi aktivitas
     * @param string|null $entityType Jenis entitas (project, module, user, tenant, task)
     * @param string|null $entityId ID entitas yang dipengaruhi
     * @param array<string, mixed> $properties Detail tambahan / payload
     * @param int|null $tenantId Override tenant id
     * @param int|null $userId Override user id
     * @param string|null $userName Override nama user
     * @return \Core\Audit\AuditLogger|int
     */
    function audit(
        ?string $action = null,
        ?string $description = null,
        ?string $entityType = null,
        ?string $entityId = null,
        array $properties = [],
        ?int $tenantId = null,
        ?int $userId = null,
        ?string $userName = null
    ): \Core\Audit\AuditLogger|int {
        $logger = app(\Core\Audit\AuditLogger::class);

        if ($action === null) {
            return $logger;
        }

        return $logger->log(
            action: $action,
            description: $description ?? $action,
            entityType: $entityType,
            entityId: $entityId,
            properties: $properties,
            tenantId: $tenantId,
            userId: $userId,
            userName: $userName
        );
    }
}


