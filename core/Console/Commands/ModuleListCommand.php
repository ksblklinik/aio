<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Console\Command;
use Core\Modules\ModuleManager;

class ModuleListCommand extends Command
{
    protected string $signature = 'module:list';
    protected string $description = 'Menampilkan tabel seluruh modul yang terpasang beserta status dan kategorinya';

    public function handle(array $arguments = [], array $options = []): int
    {
        /** @var ModuleManager $manager */
        $manager = $this->container->get(ModuleManager::class);
        $modules = $manager->listModules();

        if (empty($modules)) {
            $this->warn("Belum ada modul yang terdaftar dalam sistem.");
            return 0;
        }

        $rows = [];
        foreach ($modules as $m) {
            $status = $m['is_active'] ? "\033[32mAKTIF\033[0m" : "\033[31mNONAKTIF\033[0m";
            $type = $m['is_core'] ? "\033[35mCORE\033[0m" : "EKSTENSI";

            $rows[] = [
                'ID' => $m['id'],
                'Nama Modul' => $m['name'],
                'Kategori' => $m['category_label'] ?? $m['category'],
                'Tipe' => $type,
                'Status' => $status,
                'Versi' => 'v' . ($m['version'] ?? '1.0.0'),
            ];
        }

        $this->table(['ID', 'Nama Modul', 'Kategori', 'Tipe', 'Status', 'Versi'], $rows);
        $this->info("Total: " . count($modules) . " modul terpasang.");
        return 0;
    }
}
