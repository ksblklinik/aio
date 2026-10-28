<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Console\Command;
use Core\Privacy\TenantPrivacyManagerInterface;

class TenantExportCommand extends Command
{
    protected string $signature = 'tenant:export';
    protected string $description = 'Export all tenant data across all modules into a portable JSON archive';

    public function handle(array $arguments = [], array $options = []): int
    {
        $tenantId = isset($arguments[0]) ? (int) $arguments[0] : 0;
        if ($tenantId <= 0) {
            $this->error("Penggunaan: php aio tenant:export <tenant_id> [--output=<path>] [--no-audits]");
            return 1;
        }

        $outputFile = $options['output'] ?? null;
        $includeAudits = !isset($options['no-audits']);

        if ($outputFile === null) {
            $outputFile = getcwd() . "/storage/exports/tenant_{$tenantId}_export_" . date('Ymd_His') . ".json";
        }

        $this->info("Memulai ekspor portabilitas data untuk tenant [{$tenantId}]...");

        try {
            /** @var TenantPrivacyManagerInterface $privacy */
            $privacy = $this->container->get(TenantPrivacyManagerInterface::class);

            $result = $privacy->exportTenant($tenantId, [
                'output_file' => $outputFile,
                'include_audits' => $includeAudits,
            ]);

            $manifest = $result['manifest'];
            $this->success("Ekspor data tenant berhasil diselesaikan!");
            $this->line(" - Tenant: {$manifest['tenant_name']} (ID: {$tenantId})");
            $this->line(" - Lokasi File: {$outputFile}");
            $this->line(" - Total Entitas: {$manifest['total_entities']}");
            $this->line(" - Ukuran File: " . number_format($manifest['file_size_bytes'] ?? 0) . " bytes");
            $this->line(" - SHA-256 Digest: {$manifest['checksum_sha256']}");

            return 0;
        } catch (\Throwable $e) {
            $this->error("Gagal mengekspor data tenant: " . $e->getMessage());
            return 1;
        }
    }
}
