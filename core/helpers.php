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

if (!function_exists('base_path')) {
    /**
     * Helper global untuk mendapatkan path absolut root aplikasi
     */
    function base_path(string $path = ''): string
    {
        $base = dirname(__DIR__);
        return $path !== '' ? $base . '/' . ltrim($path, '/\\') : $base;
    }
}

if (!function_exists('app_path')) {
    /**
     * Helper global untuk mendapatkan path folder app
     */
    function app_path(string $path = ''): string
    {
        return base_path('app' . ($path !== '' ? '/' . ltrim($path, '/\\') : ''));
    }
}

if (!function_exists('storage_path')) {
    /**
     * Helper global untuk mendapatkan path folder storage
     */
    function storage_path(string $path = ''): string
    {
        return base_path('storage' . ($path !== '' ? '/' . ltrim($path, '/\\') : ''));
    }
}

if (!function_exists('public_path')) {
    /**
     * Helper global untuk mendapatkan path folder public
     */
    function public_path(string $path = ''): string
    {
        return base_path('public' . ($path !== '' ? '/' . ltrim($path, '/\\') : ''));
    }
}

if (!function_exists('config_path')) {
    /**
     * Helper global untuk mendapatkan path folder config
     */
    function config_path(string $path = ''): string
    {
        return base_path('config' . ($path !== '' ? '/' . ltrim($path, '/\\') : ''));
    }
}

if (!function_exists('resource_path')) {
    /**
     * Helper global untuk mendapatkan path folder resources
     */
    function resource_path(string $path = ''): string
    {
        return base_path('resources' . ($path !== '' ? '/' . ltrim($path, '/\\') : ''));
    }
}

if (!function_exists('db')) {
    /**
     * Helper global untuk mengakses koneksi database atau scoped query builder
     */
    function db(?string $table = null, ?string $connection = null): \Core\Database\Connection|\Core\Database\TenantScope
    {
        $conn = $connection !== null 
            ? app(\Core\Database\DatabaseManager::class)->connection($connection)
            : app(\Core\Database\Connection::class);

        if ($table !== null) {
            /** @var \Core\Database\TenantScope $scope */
            $scope = app(\Core\Database\TenantScope::class);
            if ($connection !== null) {
                return new \Core\Database\TenantScope(\Core\Database\TenantContext::getInstance(), $conn);
            }
            return $scope;
        }
        return $conn;
    }
}

if (!function_exists('queue')) {
    /**
     * Helper global untuk mengakses QueueManager atau push pekerjaan langsung ke antrean
     *
     * @param string|null $jobClass FQCN kelas yang mengimplementasikan JobInterface
     * @param array<string, mixed> $data Parameter data untuk job
     * @param int|null $tenantId Override tenant id
     * @param string $queue Nama antrean (default: 'default')
     * @param int $delaySeconds Penundaan dalam detik
     * @return \Core\Queue\QueueManager|int
     */
    function queue(
        ?string $jobClass = null,
        array $data = [],
        ?int $tenantId = null,
        string $queue = 'default',
        int $delaySeconds = 0
    ): \Core\Queue\QueueManager|int {
        /** @var \Core\Queue\QueueManager $manager */
        $manager = app(\Core\Queue\QueueManager::class);

        if ($jobClass === null) {
            return $manager;
        }

        return $manager->push($jobClass, $data, $tenantId, $queue, $delaySeconds);
    }
}

if (!function_exists('notify')) {
    /**
     * Helper global untuk mengirim in-app notification atau mengakses NotificationService
     *
     * @param int|null $userId ID penerima
     * @param string|null $title Judul notifikasi
     * @param string|null $message Isi pesan notifikasi
     * @param string $type Jenis notifikasi: 'info' | 'success' | 'warning' | 'danger'
     * @param string|null $actionUrl Tautan navigasi UI
     * @param string|null $icon Nama icon lucide
     * @param int|null $tenantId Override tenant id
     * @return \Core\Notification\NotificationService|int
     */
    function notify(
        ?int $userId = null,
        ?string $title = null,
        ?string $message = null,
        string $type = 'info',
        ?string $actionUrl = null,
        ?string $icon = null,
        ?int $tenantId = null
    ): \Core\Notification\NotificationService|int {
        /** @var \Core\Notification\NotificationService $service */
        $service = app(\Core\Notification\NotificationService::class);

        if ($userId === null && $title === null) {
            return $service;
        }

        if ($tenantId === null) {
            $activeTenant = \Core\Database\TenantContext::getInstance()->getTenantId();
            $tenantId = $activeTenant !== null ? (int) $activeTenant : 0;
        }

        return $service->send(
            tenantId: $tenantId,
            userId: (int) $userId,
            title: (string) $title,
            message: (string) ($message ?? ''),
            type: $type,
            actionUrl: $actionUrl,
            icon: $icon
        );
    }
}

if (!function_exists('csv_download')) {
    /**
     * Helper global untuk download data CSV secara streaming
     *
     * @param string $filename Nama berkas (e.g. 'laporan-invoice.csv')
     * @param array<string> $headers Judul kolom header
     * @param iterable<array<int|string, mixed>> $rows Generator atau koleksi baris data
     */
    function csv_download(string $filename, array $headers, iterable $rows): \Core\Http\Response
    {
        return \Core\Http\CsvStream::download($filename, $headers, $rows);
    }
}

if (!function_exists('csv_parse')) {
    /**
     * Helper global untuk membaca file CSV secara streaming (memory-safe)
     *
     * @param string|resource $source Path file atau resource
     * @param bool $hasHeader Apakah baris pertama adalah judul kolom
     * @return \Generator<int, array<string|int, string>>
     */
    function csv_parse(mixed $source, bool $hasHeader = true): \Generator
    {
        return \Core\Http\CsvStream::parse($source, $hasHeader);
    }
}

if (!function_exists('vector_store')) {
    /**
     * Helper global untuk mengakses layanan Vector Store atau driver tertentu
     */
    function vector_store(?string $driver = null): \Core\Vector\VectorStoreInterface
    {
        /** @var \Core\Vector\VectorManager $manager */
        $manager = app(\Core\Vector\VectorManager::class);
        return $driver !== null ? $manager->driver($driver) : $manager->driver();
    }
}

if (!function_exists('ai')) {
    /**
     * Helper global untuk mengakses AI Gateway atau driver AI tertentu
     */
    function ai(?string $driver = null): \Core\Ai\AiClientInterface
    {
        /** @var \Core\Ai\AiManager $manager */
        $manager = app(\Core\Ai\AiManager::class);
        return $driver !== null ? $manager->driver($driver) : $manager;
    }
}

if (!function_exists('geo_distance')) {
    /**
     * Helper global untuk menghitung jarak antara dua koordinat GPS (Haversine)
     *
     * @param string $unit 'km' | 'm' | 'miles'
     */
    function geo_distance(float $lat1, float $lng1, float $lat2, float $lng2, string $unit = 'km'): float
    {
        return \Core\Geo\Geo::distance($lat1, $lng1, $lat2, $lng2, $unit);
    }
}

if (!function_exists('geo_in_radius')) {
    /**
     * Helper global untuk memeriksa apakah titik koordinat berada di dalam radius geofence
     */
    function geo_in_radius(float $checkLat, float $checkLng, float $centerLat, float $centerLng, float $radiusMeters): bool
    {
        return \Core\Geo\Geo::inRadius($checkLat, $checkLng, $centerLat, $centerLng, $radiusMeters);
    }
}

if (!function_exists('ledger')) {
    /**
     * Helper global untuk mengakses layanan pembukuan saldo atomik (Ledger)
     */
    function ledger(): \Core\Ledger\LedgerManager
    {
        return app(\Core\Ledger\LedgerManager::class);
    }
}

if (!function_exists('workflow')) {
    /**
     * Helper global untuk mengakses layanan Workflow & State Machine
     */
    function workflow(?string $entityType = null): \Core\Workflow\WorkflowManager|\Core\Workflow\WorkflowDefinition
    {
        /** @var \Core\Workflow\WorkflowManager $manager */
        $manager = app(\Core\Workflow\WorkflowManager::class);
        if ($entityType !== null && $manager->hasDefinition($entityType)) {
            return $manager->getDefinition($entityType);
        }
        return $manager;
    }
}

if (!function_exists('cache')) {
    /**
     * Helper global untuk mengakses Cache Manager atau mengambil nilai cache
     */
    function cache(?string $key = null, mixed $default = null): mixed
    {
        /** @var \Core\Cache\CacheManager $cacheManager */
        $cacheManager = app(\Core\Cache\CacheManager::class);
        if ($key === null) {
            return $cacheManager;
        }
        return $cacheManager->get($key, $default);
    }
}

if (!function_exists('qr_code')) {
    /**
     * Helper global untuk menghasilkan string gambar vector SVG QR Code murni
     */
    function qr_code(string $text, int $size = 200, string $color = '#000000', string $bgColor = '#ffffff'): string
    {
        return \Core\Media\QrCode::svg($text, $size, $color, $bgColor);
    }
}

if (!function_exists('barcode')) {
    /**
     * Helper global untuk menghasilkan string gambar vector SVG Barcode Code-128 murni
     */
    function barcode(string $text, int $width = 300, int $height = 80, bool $showText = true, string $color = '#000000', string $bgColor = '#ffffff'): string
    {
        return \Core\Media\Barcode::svg($text, $width, $height, $showText, $color, $bgColor);
    }
}

if (!function_exists('notification')) {
    /**
     * Helper global untuk broadcaster notifikasi multi-channel (Database, Email, WA, Telegram)
     */
    function notification(mixed $recipient = null, int|string|null $tenantId = null): \Core\Notification\ChannelManager|\Core\Notification\PendingNotification
    {
        /** @var \Core\Notification\ChannelManager $manager */
        $manager = app(\Core\Notification\ChannelManager::class);
        if ($recipient !== null) {
            return $manager->to($recipient, $tenantId);
        }
        return $manager;
    }
}

if (!function_exists('custom_fields')) {
    /**
     * Helper global untuk mengelola Dynamic Custom Fields per tenant
     */
    function custom_fields(): \Core\CustomField\FieldManager
    {
        return app(\Core\CustomField\FieldManager::class);
    }
}

if (!function_exists('payment')) {
    /**
     * Helper global untuk Payment Gateway Manager atau eksekusi driver spesifik
     */
    function payment(?string $driver = null): \Core\Payment\PaymentManager|\Core\Payment\PaymentDriverInterface
    {
        /** @var \Core\Payment\PaymentManager $manager */
        $manager = app(\Core\Payment\PaymentManager::class);
        if ($driver !== null) {
            return $manager->driver($driver);
        }
        return $manager;
    }
}

if (!function_exists('quota')) {
    /**
     * Helper global untuk Feature Entitlement & Usage Quota Engine
     */
    function quota(?int $tenantId = null): \Core\Billing\QuotaManager
    {
        return app(\Core\Billing\QuotaManager::class);
    }
}

if (!function_exists('ledger')) {
    /**
     * Helper global untuk Universal Atomic Financial Ledger Engine
     */
    function ledger(): \Core\Ledger\LedgerManager
    {
        return app(\Core\Ledger\LedgerManager::class);
    }
}

if (!function_exists('exporter')) {
    /**
     * Helper global untuk Universal Streamed Import & Export Manager
     */
    function exporter(): \Core\Export\ExporterManager
    {
        return app(\Core\Export\ExporterManager::class);
    }
}

if (!function_exists('document')) {
    /**
     * Helper global untuk Pure-PHP Printable Document Builder (A4, thermal, receipt)
     */
    function document(string $layout = 'a4'): \Core\Document\DocumentBuilder
    {
        return \Core\Document\DocumentBuilder::create($layout);
    }
}

if (!function_exists('escpos')) {
    /**
     * Helper global untuk membuat perintah ESC/POS binary printer thermal
     */
    function escpos(int $cols = 32): \Core\Document\EscPos
    {
        return \Core\Document\EscPos::create($cols);
    }
}

if (!function_exists('encrypt')) {
    /**
     * Helper global untuk enkripsi data terotentikasi AES-256-GCM
     */
    function encrypt(mixed $value, bool $serialize = true): string
    {
        /** @var \Core\Security\Encrypter $encrypter */
        $encrypter = app(\Core\Security\Encrypter::class);
        return $encrypter->encrypt($value, $serialize);
    }
}

if (!function_exists('decrypt')) {
    /**
     * Helper global untuk dekripsi payload terotentikasi AES-256-GCM
     */
    function decrypt(string $payload): mixed
    {
        /** @var \Core\Security\Encrypter $encrypter */
        $encrypter = app(\Core\Security\Encrypter::class);
        return $encrypter->decrypt($payload);
    }
}

if (!function_exists('totp')) {
    /**
     * Helper global untuk Native Two-Factor Authentication (TOTP RFC 6238)
     */
    function totp(): \Core\Security\Totp
    {
        return app(\Core\Security\Totp::class);
    }
}

if (!function_exists('integrity')) {
    /**
     * Helper global untuk Device Integrity & Anti-Fraud Toolkit
     */
    function integrity(): \Core\Security\IntegrityGuard
    {
        return app(\Core\Security\IntegrityGuard::class);
    }
}

if (!function_exists('is_disposable_email')) {
    /**
     * Helper global untuk mendeteksi email sementara (disposable / burner email)
     */
    function is_disposable_email(string $email): bool
    {
        return integrity()->isDisposableEmail($email);
    }
}

if (!function_exists('contacts')) {
    /**
     * Helper global untuk Universal Contact & Role Registry
     */
    function contacts(): \App\Modules\Contact\Contracts\ContactRegistryInterface
    {
        return app(\App\Modules\Contact\Contracts\ContactRegistryInterface::class);
    }
}
