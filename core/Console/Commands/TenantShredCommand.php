<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Console\Command;
use Core\Privacy\TenantPrivacyManagerInterface;

class TenantShredCommand extends Command
{
    protected string $signature = 'tenant:shred';
    protected string $description = 'Permanently shred or anonymize tenant data according to GDPR / UU PDP Right to Erasure';

    public function handle(array $arguments = [], array $options = []): int
    {
        $tenantId = isset($arguments[0]) ? (int) $arguments[0] : 0;
        if ($tenantId <= 0) {
            $this->error("Penggunaan: php aio tenant:shred <tenant_id> [--anonymize] [--force] [--reason=<text>]");
            return 1;
        }

        $anonymizeOnly = isset($options['anonymize']);
        $force = isset($options['force']);
        $reason = $options['reason'] ?? null;

        if (!$force) {
            $this->error("PERINGATAN: Tindakan ini permanen dan tidak dapat dibatalkan! Tambahkan flag --force untuk melanjutkan.");
            return 1;
        }

        /** @var TenantPrivacyManagerInterface $privacy */
        $privacy = $this->container->get(TenantPrivacyManagerInterface::class);

        try {
            if ($anonymizeOnly) {
                $this->info("Memulai proses anonimisasi PII (Right to Erasure) untuk tenant [{$tenantId}]...");
                $result = $privacy->anonymizeTenant($tenantId, $reason);

                $this->success("Anonimisasi tenant berhasil diselesaikan.");
                $this->line(" - Tenant: {$result['tenant_name']} (ID: {$tenantId})");
                $this->line(" - Kunci Enkripsi Kriptografis: Dimusnahkan (Crypto-Shredded)");
                $this->line(" - Entitas Dianonimkan: " . json_encode($result['anonymized_entities']));
                return 0;
            }

            $this->warn("MEMULAI PEMUSNAHAN TOTAL DATA TENANT [{$tenantId}]...");
            $result = $privacy->shredTenant($tenantId, true, $reason);

            $cert = $result['certificate'];
            $this->success("Pemusnahan total data tenant berhasil diselesaikan!");
            $this->line(" - Certificate ID: {$cert['certificate_id']}");
            $this->line(" - Tenant Asal: {$cert['tenant_name']} (ID: {$tenantId})");
            $this->line(" - Total Baris Dimusnahkan: {$cert['total_rows_purged']}");
            $this->line(" - Tanda Tangan Sertifikat: {$cert['destruction_signature']}");

            return 0;
        } catch (\Throwable $e) {
            $this->error("Gagal melakukan shredding data tenant: " . $e->getMessage());
            return 1;
        }
    }
}
