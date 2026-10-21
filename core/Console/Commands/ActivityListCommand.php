<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Audit\AuditLogger;
use Core\Console\Command;

class ActivityListCommand extends Command
{
    protected string $signature = 'activity:list';
    protected string $description = 'Menampilkan tabel riwayat jejak audit (Audit Logs & Activity Timeline)';

    public function handle(array $arguments = [], array $options = []): int
    {
        /** @var AuditLogger $logger */
        $logger = $this->container->get(AuditLogger::class);

        $limit = isset($options['limit']) ? (int) $options['limit'] : 20;
        $tenantId = isset($options['tenant']) ? (int) $options['tenant'] : null;

        if ($tenantId !== null) {
            $this->info("Menampilkan {$limit} aktivitas terakhir untuk Tenant ID #{$tenantId}...");
            $logs = $logger->getForTenant($tenantId, $limit);
        } else {
            $this->info("Menampilkan {$limit} aktivitas terakhir secara global...");
            $logs = $logger->getRecent($limit);
        }

        if (empty($logs)) {
            $this->warn("Belum ada riwayat aktivitas yang tercatat.");
            return 0;
        }

        $rows = [];
        foreach ($logs as $log) {
            $userName = $log['user_name'] ?? 'System/Tamu';
            $action = $log['action'] ?? '-';
            $entity = ($log['entity_type'] ?? '-') . ($log['entity_id'] ? " #{$log['entity_id']}" : '');
            $tenant = $log['tenant_name'] ?? ($log['tenant_id'] ? "Tenant #{$log['tenant_id']}" : 'Global');

            $rows[] = [
                'ID' => (string) $log['id'],
                'Waktu' => (string) $log['created_at'],
                'Workspace' => (string) $tenant,
                'Pelaku' => (string) $userName,
                'Aksi' => "\033[36m" . $action . "\033[0m",
                'Deskripsi' => (string) $log['description'],
                'Entitas' => (string) $entity,
            ];
        }

        $this->table(['ID', 'Waktu', 'Workspace', 'Pelaku', 'Aksi', 'Deskripsi', 'Entitas'], $rows);
        $this->info("Total: " . count($rows) . " catatan audit ditampilkan.");

        return 0;
    }
}
