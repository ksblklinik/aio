<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Console\Command;
use Core\Database\Connection;
use Core\Database\Seeder;
use Core\Modules\ModuleManager;

class SeedCommand extends Command
{
    protected string $signature = 'db:seed {--module= : ID modul spesifik yang ingin di-seed datanya}';
    protected string $description = 'Menjalankan seeder database untuk mengisi data awal (core atau modul)';

    public function handle(array $arguments = [], array $options = []): int
    {
        /** @var Connection $db */
        $db = $this->container->get(Connection::class);
        $moduleManager = $this->container->has(ModuleManager::class) ? $this->container->get(ModuleManager::class) : null;
        $module = !empty($options['module']) ? (string) $options['module'] : null;

        $basePath = dirname(__DIR__, 3);

        if ($module !== null) {
            $this->info("Menjalankan seeder untuk modul '{$module}'...");
            $folder = $moduleManager ? $moduleManager->resolveFolderForModule($module) : ucfirst($module);
            $seederDir = $basePath . "/app/Modules/{$folder}/Database/Seeders";

            if (!is_dir($seederDir)) {
                $this->line("Direktori seeder modul tidak ditemukan: app/Modules/{$folder}/Database/Seeders");
                return 0;
            }

            // Cari class seeder di folder tersebut
            $seederFiles = glob($seederDir . '/*.php') ?: [];
            if (empty($seederFiles)) {
                $this->line("Tidak ada file seeder di folder modul.");
                return 0;
            }

            foreach ($seederFiles as $file) {
                $className = pathinfo($file, PATHINFO_FILENAME);
                $fullClass = "App\\Modules\\{$folder}\\Database\\Seeders\\{$className}";
                if (file_exists($file)) {
                    require_once $file;
                }
                if (class_exists($fullClass) && is_subclass_of($fullClass, Seeder::class)) {
                    /** @var Seeder $instance */
                    $instance = new $fullClass($db);
                    $instance->run();
                    $this->success("Seeder berhasil dieksekusi: {$fullClass}");
                }
            }

            return 0;
        }

        // Jalankan core seeder utama jika ada
        $this->info("Menjalankan database seeder utama...");
        $coreSeederFile = $basePath . '/database/seeders/DatabaseSeeder.php';
        if (file_exists($coreSeederFile)) {
            require_once $coreSeederFile;
            if (class_exists('Database\\Seeders\\DatabaseSeeder')) {
                $seeder = new \Database\Seeders\DatabaseSeeder($db);
                $seeder->run();
                $this->success("Core seeder selesai dieksekusi.");
                return 0;
            }
        }

        // Fallback jika ada scripts/init_db.php
        $initScript = $basePath . '/scripts/init_db.php';
        if (file_exists($initScript)) {
            $this->line("Menjalankan data inisialisasi dari scripts/init_db.php...");
            include $initScript;
            $this->success("Database seeding selesai.");
            return 0;
        }

        $this->warn("Tidak ada file seeder utama (database/seeders/DatabaseSeeder.php) ditemukan.");
        return 0;
    }
}
