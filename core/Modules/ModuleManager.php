<?php

declare(strict_types=1);

namespace Core\Modules;

use Core\Database\Connection;
use DomainException;
use PDO;

class ModuleManager
{
    private PDO $pdo;
    private string $modulesBasePath;
    private string $pagesBasePath;

    public function __construct(?PDO $pdo = null, ?string $modulesBasePath = null, ?string $pagesBasePath = null)
    {
        $this->pdo = $pdo ?? Connection::getInstance()->getPdo();
        $this->modulesBasePath = $modulesBasePath ?? dirname(__DIR__, 2) . '/app/Modules';
        $this->pagesBasePath = $pagesBasePath ?? dirname(__DIR__, 2) . '/resources/js/Pages';
        
        $this->ensureTableExists();
        $this->syncFromFilesystem();
    }

    /**
     * Pastikan tabel system_modules tersedia
     */
    private function ensureTableExists(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS system_modules (
            id TEXT PRIMARY KEY,
            name TEXT NOT NULL,
            version TEXT NOT NULL DEFAULT '1.0.0',
            author TEXT NOT NULL DEFAULT 'aio Core',
            description TEXT,
            category TEXT NOT NULL DEFAULT 'General',
            icon TEXT NOT NULL DEFAULT 'Layers',
            route TEXT NOT NULL,
            dependencies TEXT DEFAULT '[]',
            is_core INTEGER NOT NULL DEFAULT 0,
            is_active INTEGER NOT NULL DEFAULT 1,
            table_name TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )";
        $this->pdo->exec($sql);
    }

    /**
     * Memindai file module.json dari filesystem dan menyelaraskan ke database
     */
    public function syncFromFilesystem(): array
    {
        if (!is_dir($this->modulesBasePath)) {
            return [];
        }

        $folders = scandir($this->modulesBasePath) ?: [];
        $synced = [];

        foreach ($folders as $folder) {
            if ($folder === '.' || $folder === '..') {
                continue;
            }

            $moduleDir = $this->modulesBasePath . '/' . $folder;
            $manifestFile = $moduleDir . '/module.json';

            if (!is_dir($moduleDir) || !file_exists($manifestFile)) {
                continue;
            }

            $content = json_decode((string) file_get_contents($manifestFile), true);
            if (!is_array($content) || empty($content['id'])) {
                continue;
            }

            $id = (string) $content['id'];
            $name = (string) ($content['name'] ?? ucfirst($folder));
            $version = (string) ($content['version'] ?? '1.0.0');
            $author = (string) ($content['author'] ?? 'aio Core');
            $description = (string) ($content['description'] ?? '');
            $category = (string) ($content['category'] ?? 'General');
            $icon = (string) ($content['icon'] ?? 'Layers');
            $route = (string) ($content['route'] ?? '/' . strtolower($folder));
            $dependencies = json_encode($content['dependencies'] ?? []);
            $isCore = !empty($content['is_core']) ? 1 : 0;
            $tableName = (string) ($content['table_name'] ?? '');

            // Cek apakah sudah ada di database
            $stmt = $this->pdo->prepare("SELECT id, is_active FROM system_modules WHERE id = ?");
            $stmt->execute([$id]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                // Update metadata tanpa mengubah status is_active yang sudah disetel user
                $update = $this->pdo->prepare("UPDATE system_modules SET 
                    name = ?, version = ?, author = ?, description = ?, category = ?, 
                    icon = ?, route = ?, dependencies = ?, is_core = ?, table_name = ?, updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?");
                $update->execute([
                    $name, $version, $author, $description, $category,
                    $icon, $route, $dependencies, $isCore, $tableName, $id
                ]);
            } else {
                // Insert baru
                $insert = $this->pdo->prepare("INSERT INTO system_modules 
                    (id, name, version, author, description, category, icon, route, dependencies, is_core, is_active, table_name)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)");
                $insert->execute([
                    $id, $name, $version, $author, $description, $category,
                    $icon, $route, $dependencies, $isCore, $tableName
                ]);
            }

            $synced[] = $id;
        }

        return $synced;
    }

    /**
     * Daftar seluruh modul yang terdaftar
     */
    public function listModules(): array
    {
        $this->syncFromFilesystem();

        $stmt = $this->pdo->query("SELECT * FROM system_modules ORDER BY is_core DESC, name ASC");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function ($row) {
            $row['dependencies'] = json_decode($row['dependencies'] ?? '[]', true) ?: [];
            $row['is_core'] = (bool) $row['is_core'];
            $row['is_active'] = (bool) $row['is_active'];
            $row['folder'] = $this->resolveFolderForModule((string) $row['id']);
            return $row;
        }, $rows);
    }

    /**
     * Daftar modul yang sedang aktif
     */
    public function getActiveModules(): array
    {
        $all = $this->listModules();
        return array_values(array_filter($all, fn($m) => $m['is_active'] === true));
    }

    /**
     * Daftar item navigasi dinamis untuk sidebar (hanya modul dengan rute tampilan aktif)
     */
    public function getActiveNavItems(): array
    {
        $active = $this->getActiveModules();
        $navItems = [];

        // Modul bawaan seperti Auth dan Workspaces tidak memiliki tampilan sidebar langsung
        $hiddenFromNav = ['auth', 'workspaces'];

        foreach ($active as $m) {
            if (in_array($m['id'], $hiddenFromNav, true)) {
                continue;
            }

            $navItems[] = [
                'id' => $m['id'],
                'label' => $m['name'],
                'icon' => $m['icon'],
                'href' => $m['route'],
                'category' => $m['category'],
            ];
        }

        return $navItems;
    }

    /**
     * Toggle status aktif / nonaktif modul dengan Dependency Guard
     */
    public function toggleActive(string $id, ?bool $targetState = null): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM system_modules WHERE id = ?");
        $stmt->execute([$id]);
        $module = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        if (!$module) {
            throw new DomainException("Modul [{$id}] tidak ditemukan.");
        }

        if ((int) $module['is_core'] === 1) {
            throw new DomainException("Modul inti [{$module['name']}] dilindungi dan tidak dapat dinonaktifkan.");
        }

        $currentState = (bool) $module['is_active'];
        $nextState = $targetState !== null ? $targetState : !$currentState;

        // Dependency Guard: Mencegah menonaktifkan jika ada modul aktif lain yang membutuhkannya
        if (!$nextState) {
            $this->validateNoActiveDependents($id);
        } else {
            // Validasi jika modul ini membutuhkan modul lain yang sedang nonaktif
            $this->validatePrerequisitesActive($module);
        }

        $update = $this->pdo->prepare("UPDATE system_modules SET is_active = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $update->execute([$nextState ? 1 : 0, $id]);

        $module['is_active'] = $nextState;
        $module['is_core'] = (bool) $module['is_core'];
        $module['dependencies'] = json_decode($module['dependencies'] ?? '[]', true) ?: [];

        return $module;
    }

    /**
     * Dependency Guard: Pastikan tidak ada modul aktif lain yang bergantung pada modul ini
     */
    private function validateNoActiveDependents(string $id): void
    {
        $activeModules = $this->getActiveModules();
        $dependents = [];

        foreach ($activeModules as $active) {
            if ($active['id'] === $id) {
                continue;
            }

            $deps = $active['dependencies'] ?? [];
            if (in_array($id, $deps, true)) {
                $dependents[] = $active['name'];
            }
        }

        if (!empty($dependents)) {
            $list = implode(', ', $dependents);
            throw new DomainException("Modul ini tidak dapat dinonaktifkan karena dibutuhkan oleh modul aktif: {$list}.");
        }
    }

    /**
     * Dependency Guard: Pastikan modul prasyarat sudah dalam status aktif
     */
    private function validatePrerequisitesActive(array $module): void
    {
        $deps = json_decode($module['dependencies'] ?? '[]', true) ?: [];
        if (empty($deps)) {
            return;
        }

        $activeIds = array_column($this->getActiveModules(), 'id');
        $missing = [];

        foreach ($deps as $requiredId) {
            if (!in_array($requiredId, $activeIds, true)) {
                $stmt = $this->pdo->prepare("SELECT name FROM system_modules WHERE id = ?");
                $stmt->execute([$requiredId]);
                $reqName = $stmt->fetchColumn() ?: $requiredId;
                $missing[] = $reqName;
            }
        }

        if (!empty($missing)) {
            $list = implode(', ', $missing);
            throw new DomainException("Harap aktifkan modul prasyarat terlebih dahulu: {$list}.");
        }
    }

    /**
     * Membuat template modul baru (Scaffolding otomatis)
     */
    public function createModuleScaffold(array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new DomainException("Nama modul wajib diisi.");
        }

        $id = strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($data['id'] ?? strtolower($name))) ?: '');
        if ($id === '') {
            throw new DomainException("ID/Slug modul tidak valid.");
        }

        $stmt = $this->pdo->prepare("SELECT id FROM system_modules WHERE id = ?");
        $stmt->execute([$id]);
        if ($stmt->fetch()) {
            throw new DomainException("Modul dengan ID [{$id}] sudah terdaftar.");
        }

        $folderName = ucfirst($id);
        $moduleDir = $this->modulesBasePath . '/' . $folderName;
        $frontendDir = $this->pagesBasePath . '/' . $folderName;

        if (file_exists($moduleDir)) {
            throw new DomainException("Folder modul [{$folderName}] sudah ada di filesystem.");
        }

        // Buat struktur direktori
        mkdir($moduleDir . '/Controllers', 0755, true);
        mkdir($moduleDir . '/Models', 0755, true);
        mkdir($moduleDir . '/database/migrations', 0755, true);
        mkdir($frontendDir, 0755, true);

        $description = trim((string) ($data['description'] ?? "Modul {$name} untuk framework aio."));
        $category = trim((string) ($data['category'] ?? 'Bisnis'));
        $icon = trim((string) ($data['icon'] ?? 'Package'));
        $route = '/' . $id;
        $dependencies = is_array($data['dependencies'] ?? null) ? $data['dependencies'] : [];
        $tableName = $id;

        // 1. Tulis module.json
        $manifest = [
            'id' => $id,
            'name' => $name,
            'version' => '1.0.0',
            'author' => (string) ($data['author'] ?? 'Admin'),
            'description' => $description,
            'category' => $category,
            'icon' => $icon,
            'route' => $route,
            'dependencies' => $dependencies,
            'is_core' => false,
            'is_active' => true,
            'table_name' => $tableName,
        ];
        file_put_contents($moduleDir . '/module.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // 2. Tulis Controller
        $controllerContent = <<<PHP
<?php

declare(strict_types=1);

namespace App\Modules\\{$folderName}\Controllers;

use Core\Http\Request;
use Core\Http\Response;

class {$folderName}Controller
{
    public function index(Request \$request): Response
    {
        return inertia('{$folderName}/Index', [
            'title' => '{$name}',
            'message' => 'Selamat datang di modul {$name}!',
        ]);
    }
}
PHP;
        file_put_contents($moduleDir . "/Controllers/{$folderName}Controller.php", $controllerContent);

        // 3. Tulis Routes
        $routesContent = <<<PHP
<?php

declare(strict_types=1);

use App\Modules\\{$folderName}\Controllers\\{$folderName}Controller;
use App\Modules\Auth\Middleware\Authenticate;
use Core\Router;

/** @var Router \$router */

\$router->group(['middleware' => [Authenticate::class]], function (Router \$r) {
    \$r->get('{$route}', [{$folderName}Controller::class, 'index']);
});
PHP;
        file_put_contents($moduleDir . '/routes.php', $routesContent);

        // 4. Tulis Frontend React Page
        $reactContent = <<<TSX
import React from 'react';
import AppLayout from '../../Layouts/AppLayout';
import { Package } from 'lucide-react';

interface Props {
  title: string;
  message: string;
}

export default function Index({ title, message }: Props) {
  return (
    <AppLayout title={title}>
      <div className="space-y-6 max-w-7xl mx-auto">
        <div className="p-6 rounded-xl bg-surface border border-border-subtle shadow-xs flex items-center gap-4">
          <div className="h-12 w-12 rounded-xl bg-brand-primary-light flex items-center justify-center text-brand-primary">
            <Package className="h-6 w-6" />
          </div>
          <div>
            <h2 className="text-xl font-bold text-text-main">{title}</h2>
            <p className="text-xs text-text-muted mt-1">{message}</p>
          </div>
        </div>
      </div>
    </AppLayout>
  );
}
TSX;
        file_put_contents($frontendDir . '/Index.tsx', $reactContent);

        // 5. Simpan ke database
        $insert = $this->pdo->prepare("INSERT INTO system_modules 
            (id, name, version, author, description, category, icon, route, dependencies, is_core, is_active, table_name)
            VALUES (?, ?, '1.0.0', ?, ?, ?, ?, ?, ?, 0, 1, ?)");
        $insert->execute([
            $id, $name, $manifest['author'], $description, $category,
            $icon, $route, json_encode($dependencies), $tableName
        ]);

        return $this->listModules();
    }

    /**
     * Hapus modul secara permanen (Clean Wipe: Drop Table + Delete Files)
     */
    public function deleteModule(string $id): bool
    {
        $stmt = $this->pdo->prepare("SELECT * FROM system_modules WHERE id = ?");
        $stmt->execute([$id]);
        $module = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        if (!$module) {
            throw new DomainException("Modul [{$id}] tidak ditemukan.");
        }

        if ((int) $module['is_core'] === 1) {
            throw new DomainException("Modul inti [{$module['name']}] dilindungi dan tidak dapat dihapus.");
        }

        // Dependency Guard: Mencegah menghapus modul jika ada modul lain yang membutuhkannya
        $this->validateNoActiveDependents($id);

        // 1. Clean Wipe Database: Drop Table jika didefinisikan
        $tableName = trim((string) ($module['table_name'] ?? ''));
        if ($tableName !== '' && preg_match('/^[a-zA-Z0-9_]+$/', $tableName)) {
            // Hindari drop tabel core seperti users atau tenants
            if (!in_array($tableName, ['users', 'tenants', 'tenant_user', 'system_modules', 'migrations'], true)) {
                $this->pdo->exec("DROP TABLE IF EXISTS {$tableName}");
            }
        }

        // 2. Hapus file backend
        $folderName = $this->resolveFolderForModule($id);
        $moduleDir = $this->modulesBasePath . '/' . $folderName;
        if (is_dir($moduleDir)) {
            $this->recursiveDeleteDirectory($moduleDir);
        }

        // 3. Hapus file frontend jika ada
        $frontendDir = $this->pagesBasePath . '/' . $folderName;
        if (is_dir($frontendDir)) {
            $this->recursiveDeleteDirectory($frontendDir);
        }

        // 4. Hapus dari database
        $del = $this->pdo->prepare("DELETE FROM system_modules WHERE id = ?");
        $del->execute([$id]);

        return true;
    }

    /**
     * Cari nama folder asli di filesystem berdasarkan id modul
     */
    private function resolveFolderForModule(string $id): string
    {
        if (!is_dir($this->modulesBasePath)) {
            return ucfirst($id);
        }

        $folders = scandir($this->modulesBasePath) ?: [];
        foreach ($folders as $folder) {
            if ($folder === '.' || $folder === '..') {
                continue;
            }

            $manifest = $this->modulesBasePath . '/' . $folder . '/module.json';
            if (file_exists($manifest)) {
                $data = json_decode((string) file_get_contents($manifest), true);
                if (is_array($data) && ($data['id'] ?? '') === $id) {
                    return $folder;
                }
            }
        }

        return ucfirst($id);
    }

    /**
     * Hapus direktori beserta seluruh isinya secara rekursif
     */
    private function recursiveDeleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->recursiveDeleteDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
