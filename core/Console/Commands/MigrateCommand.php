<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Console\Command;
use Core\Database\Connection;
use Core\Database\Migrator;

class MigrateCommand extends Command
{
    protected string $signature = 'migrate';
    protected string $description = 'Menjalankan seluruh file migrasi skema SQL database';

    public function handle(array $arguments = [], array $options = []): int
    {
        $this->info("Menjalankan migrasi database...");

        /** @var Connection $db */
        $db = $this->container->get(Connection::class);
        $migrator = new Migrator($db);

        $migrationsDir = dirname(__DIR__, 3) . '/database/migrations';
        $applied = $migrator->runMigrationsFrom($migrationsDir);

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
