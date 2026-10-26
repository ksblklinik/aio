<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Console\Command;
use Core\Database\Connection;
use Core\Database\Migrator;

use Core\Modules\ModuleManager;

class MigrateCommand extends Command
{
    protected string $signature = 'migrate {--module= : ID modul spesifik yang ingin dimigrasikan}';
    protected string $description = 'Menjalankan seluruh file migrasi skema SQL database (core dan modul aktif)';

    public function handle(array $arguments = [], array $options = []): int
    {
        /** @var Connection $db */
        $db = $this->container->get(Connection::class);
        $moduleManager = $this->container->has(ModuleManager::class) ? $this->container->get(ModuleManager::class) : null;
        $migrator = new Migrator($db, null, $moduleManager);

        $module = !empty($options['module']) ? (string) $options['module'] : null;
        $target = $module ? "modul '{$module}'" : "seluruh modul & core";

        $this->info("Menjalankan migrasi database ({$target})...");

        $applied = $migrator->runAllMigrations($module);

        if (empty($applied)) {
            $this->line("Tidak ada migrasi baru untuk dijalankan (Database up-to-date).");
            return 0;
        }

        foreach ($applied as $m) {
            $this->success("Migrasi selesai: {$m}");
        }

        $this->info("Total: " . count($applied) . " file migrasi berhasil dieksekusi.");
        return 0;
    }
}
