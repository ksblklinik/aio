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

            $this->success("Modul [{$created['name']}] berhasil di-scaffold!");
            $this->line("  - Manifest:   app/Modules/{$created['id']}/module.json");
            $this->line("  - Controller: app/Modules/{$created['id']}/Controllers/{$created['name']}Controller.php");
            $this->line("  - Routes:     app/Modules/{$created['id']}/routes.php");
            $this->line("  - Frontend:   resources/js/Pages/{$created['id']}/Index.tsx");
            return 0;
        } catch (DomainException $e) {
            $this->error("Gagal membuat modul: " . $e->getMessage());
            return 1;
        }
    }
}
