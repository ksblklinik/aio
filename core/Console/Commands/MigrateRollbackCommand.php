<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Console\Command;
use Core\Database\Connection;
use Core\Database\Migrator;
use Core\Modules\ModuleManager;

class MigrateRollbackCommand extends Command
{
    protected string $signature = 'migrate:rollback {--module= : ID modul spesifik yang ingin di-rollback}';
    protected string $description = 'Membalikkan (rollback) batch migrasi database terakhir';

    public function handle(array $arguments = [], array $options = []): int
    {
        /** @var Connection $db */
        $db = $this->container->get(Connection::class);
        $moduleManager = $this->container->has(ModuleManager::class) ? $this->container->get(ModuleManager::class) : null;
        $migrator = new Migrator($db, null, $moduleManager);

        $module = !empty($options['module']) ? (string) $options['module'] : null;

        $target = $module ? "modul '{$module}'" : "seluruh modul & core";
        $this->info("Melakukan rollback batch migrasi terakhir ({$target})...");

        $rolledBack = $migrator->rollbackLastBatch($module);

        if (empty($rolledBack)) {
            $this->line("Tidak ada migrasi yang di-rollback (belum ada batch atau down_sql kosong).");
            return 0;
        }

        foreach ($rolledBack as $m) {
            $this->warn("Rollback selesai: {$m}");
        }

        $this->success("Total " . count($rolledBack) . " migrasi berhasil di-rollback.");
        return 0;
    }
}
