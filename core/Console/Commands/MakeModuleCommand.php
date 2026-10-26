<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Console\Command;
use Core\Modules\CategoryRegistry;
use Core\Modules\ModuleManager;
use DomainException;

class MakeModuleCommand extends Command
{
    protected string $signature = 'make:module';
    protected string $description = 'Membuat kerangka kerja modul baru (Controller, Routes, Model, dan React Page)';

    public function handle(array $arguments = [], array $options = []): int
    {
        $name = $arguments[0] ?? null;

        if (!$name) {
            $this->error("Nama modul wajib diisi. Contoh: php aio make:module Invoicing");
            return 1;
        }

        $id = $options['id'] ?? strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $name));
        $category = CategoryRegistry::normalizeKey((string) ($options['category'] ?? 'collaboration'));
        $description = (string) ($options['description'] ?? "Modul {$name} untuk sistem organisasi.");

        $this->info("Menyusun kerangka kerja modul [{$name}] (ID: {$id}, Kategori: {$category})...");

        /** @var ModuleManager $manager */
        $manager = $this->container->get(ModuleManager::class);

        try {
            $created = $manager->createModuleScaffold([
                'name' => $name,
                'id' => $id,
                'category' => $category,
                'description' => $description,
                'author' => 'Developer',
                'icon' => 'Package',
            ]);

            $folderName = ucfirst($id);
            $this->success("Modul [{$name}] berhasil di-scaffold!");
            $this->line("  - Manifest:   app/Modules/{$folderName}/module.json");
            $this->line("  - Provider:   app/Modules/{$folderName}/{$folderName}ServiceProvider.php");
            $this->line("  - Migration:  app/Modules/{$folderName}/Database/Migrations/001_create_{$id}_table.sql");
            $this->line("  - Seeder:     app/Modules/{$folderName}/Database/Seeders/{$folderName}Seeder.php");
            $this->line("  - Controller: app/Modules/{$folderName}/Controllers/{$folderName}Controller.php");
            $this->line("  - Routes:     app/Modules/{$folderName}/routes.php");
            $this->line("  - Page:       app/Modules/{$folderName}/Pages/Index.tsx");
            return 0;
        } catch (DomainException $e) {
            $this->error("Gagal membuat modul: " . $e->getMessage());
            return 1;
        }
    }
}
