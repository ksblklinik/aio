<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Console\Command;
use Core\Database\Connection;
use Core\Database\Migrator;
use Core\Modules\ModuleManager;
use Core\Security\AppKey;
use Database\Seeders\DatabaseSeeder;
use Throwable;

class AppInstallCommand extends Command
{
    protected string $signature = 'app:install {--force : Timpa konfigurasi atau database} {--no-seed : Lewati seeding database} {--admin-name= : Nama superadmin awal} {--admin-email= : Email superadmin awal} {--admin-password= : Password superadmin awal} {--app-name= : Nama kustom aplikasi} {--env-file= : Jalur kustom file .env} {--non-interactive : Jalankan secara otomatis tanpa interaksi prompt}';
    protected string $description = 'Menjalankan wizard instalasi satu-langkah (setup .env, generate APP_KEY, migrasi database, seeding, dan akun superadmin)';

    public function handle(array $arguments = [], array $options = []): int
    {
        $isNonInteractive = isset($options['non-interactive']);
        $force = isset($options['force']);
        $noSeed = isset($options['no-seed']);
        $basePath = dirname(__DIR__, 3);

        $this->line("\033[1;36m================================================\033[0m");
        $this->line("\033[1;36m       aio Framework - App Setup & Installer   \033[0m");
        $this->line("\033[1;36m================================================\033[0m");
        $this->line("");

        // ---------------------------------------------------------------------
        // 1. Setup File .env
        // ---------------------------------------------------------------------
        $this->info("[1/5] Memeriksa file konfigurasi lingkungan (.env)...");
        $envPath = isset($options['env-file']) ? (string) $options['env-file'] : ($basePath . DIRECTORY_SEPARATOR . '.env');
        $examplePath = $basePath . DIRECTORY_SEPARATOR . '.env.example';

        if (!file_exists($envPath) || $force) {
            if (file_exists($examplePath)) {
                copy($examplePath, $envPath);
                $this->success("File .env berhasil dibuat dari .env.example.");
            } else {
                file_put_contents($envPath, "APP_NAME=\"aio SaaS\"\nAPP_ENV=local\nAPP_DEBUG=true\nAPP_KEY=\n");
                $this->success("File .env dasar berhasil dibuat.");
            }
        } else {
            $this->line("  ℹ File .env sudah ada.");
        }

        // Tentukan Nama Aplikasi
        $appName = $options['app-name'] ?? null;
        if ($appName === null && !$isNonInteractive) {
            $appName = $this->ask("Nama Aplikasi", "aio SaaS");
        }
        if (!empty($appName)) {
            $this->updateEnvVariable($envPath, 'APP_NAME', (string) $appName);
            $this->success("Nama aplikasi disetel ke: '{$appName}'");
        }

        // ---------------------------------------------------------------------
        // 2. Setup Kunci Enkripsi Aplikasi (APP_KEY)
        // ---------------------------------------------------------------------
        $this->info("[2/5] Menyiapkan kunci kriptografi aplikasi (APP_KEY)...");
        $envContent = (string) file_get_contents($envPath);
        $needsKey = $force;

        if (preg_match('/^APP_KEY=(.*)$/m', $envContent, $matches)) {
            $existingKey = trim($matches[1]);
            if ($existingKey === '') {
                $needsKey = true;
            }
        } else {
            $needsKey = true;
        }

        if ($needsKey) {
            $newKey = AppKey::generateKey();
            $this->updateEnvVariable($envPath, 'APP_KEY', $newKey);
            $this->success("APP_KEY baru berhasil dibuat dan disimpan ke .env.");
        } else {
            $this->line("  ℹ APP_KEY sudah terpasang.");
        }

        // ---------------------------------------------------------------------
        // 3. Migrasi Skema Database
        // ---------------------------------------------------------------------
        $this->info("[3/5] Menyiapkan database dan mengeksekusi migrasi skema...");
        try {
            /** @var Connection $db */
            $db = $this->container->get(Connection::class);
            $moduleManager = $this->container->has(ModuleManager::class) ? $this->container->get(ModuleManager::class) : null;
            $migrator = new Migrator($db, null, $moduleManager);

            $applied = $migrator->runAllMigrations();
            if (!empty($applied)) {
                $this->success("Berhasil mengeksekusi " . count($applied) . " file migrasi database.");
            } else {
                $this->line("  ℹ Skema database sudah mutakhir (up-to-date).");
            }
        } catch (Throwable $e) {
            $this->error("Gagal menjalankan migrasi database: " . $e->getMessage());
            return 1;
        }

        // ---------------------------------------------------------------------
        // 4. Seeding Data Awal
        // ---------------------------------------------------------------------
        $this->info("[4/5] Inisialisasi data dasar (Seeding)...");
        $shouldSeed = !$noSeed;
        if (!$isNonInteractive && !$noSeed) {
            $shouldSeed = $this->confirm("Jalankan seeding data demo (tenants & default users)?", true);
        }

        if ($shouldSeed) {
            try {
                $coreSeederFile = $basePath . '/database/seeders/DatabaseSeeder.php';
                if (file_exists($coreSeederFile)) {
                    require_once $coreSeederFile;
                    if (class_exists('Database\\Seeders\\DatabaseSeeder')) {
                        $seeder = new DatabaseSeeder($db);
                        $seeder->run();
                        $this->success("Data dasar berhasil di-seed (DatabaseSeeder).");
                    }
                } elseif (file_exists($basePath . '/scripts/init_db.php')) {
                    include $basePath . '/scripts/init_db.php';
                    $this->success("Data awal berhasil di-seed dari scripts/init_db.php.");
                } else {
                    $this->warn("File seeder utama tidak ditemukan, melewati seeding.");
                }
            } catch (Throwable $e) {
                $this->warn("Peringatan saat seeding: " . $e->getMessage());
            }
        } else {
            $this->line("  ℹ Melewati seeding data awal.");
        }

        // ---------------------------------------------------------------------
        // 5. Akun Superadmin Platform
        // ---------------------------------------------------------------------
        $this->info("[5/5] Menyiapkan akun Platform Superadmin...");
        $adminName = $options['admin-name'] ?? null;
        $adminEmail = $options['admin-email'] ?? null;
        $adminPassword = $options['admin-password'] ?? null;

        if (!$isNonInteractive) {
            if (!$adminEmail) {
                $adminEmail = $this->ask("Alamat Email Superadmin", "admin@example.com");
            }
            if (!$adminName) {
                $adminName = $this->ask("Nama Lengkap Superadmin", "Platform Superadmin");
            }
            if (!$adminPassword) {
                $adminPassword = $this->secret("Password Superadmin", "superadmin123");
            }
        } else {
            $adminEmail = $adminEmail ?: 'admin@example.com';
            $adminName = $adminName ?: 'Platform Superadmin';
            $adminPassword = $adminPassword ?: 'superadmin123';
        }

        try {
            $cleanEmail = trim(strtolower((string) $adminEmail));
            $existing = $db->selectOne("SELECT id, name, email FROM users WHERE email = ?", [$cleanEmail]);

            if ($existing) {
                $db->statement("UPDATE users SET is_superadmin = 1 WHERE id = ?", [(int) $existing['id']]);
                $this->success("Pengguna yang ada [{$existing['email']}] dipromosikan menjadi Platform Superadmin.");
            } else {
                $passwordHash = password_hash((string) $adminPassword, PASSWORD_DEFAULT);
                $userId = $db->insert('users', [
                    'name' => $adminName,
                    'email' => $cleanEmail,
                    'password_hash' => $passwordHash,
                    'is_superadmin' => 1,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);

                // Pastikan superadmin terdaftar di tenant default (id 1) jika ada
                $tenant1 = $db->selectOne("SELECT id FROM tenants WHERE id = 1");
                if ($tenant1) {
                    $hasPivot = $db->selectOne("SELECT 1 FROM tenant_user WHERE tenant_id = 1 AND user_id = ?", [$userId]);
                    if (!$hasPivot) {
                        $db->statement(
                            "INSERT INTO tenant_user (tenant_id, user_id, role) VALUES (1, ?, 'owner')",
                            [$userId]
                        );
                    }
                }

                $this->success("Akun Superadmin baru berhasil dibuat.");
            }
        } catch (Throwable $e) {
            $this->error("Gagal membuat akun superadmin: " . $e->getMessage());
            return 1;
        }

        // ---------------------------------------------------------------------
        // Ringkasan Selesai
        // ---------------------------------------------------------------------
        $this->line("");
        $this->line("\033[1;32m================================================\033[0m");
        $this->line("\033[1;32m  🎉 INSTALASI SELESAI - APLIKASI SIAP DIGUNAKAN\033[0m");
        $this->line("\033[1;32m================================================\033[0m");
        $this->line("Kredensial Superadmin:");
        $this->line("  • Email:    \033[36m{$adminEmail}\033[0m");
        $this->line("  • Password: \033[33m{$adminPassword}\033[0m");
        $this->line("");
        $this->line("Langkah Selanjutnya untuk Menjalankan:");
        $this->line("  1. Jalankan dev server backend:  \033[36mphp -S localhost:8000 public/index.php\033[0m");
        $this->line("  2. Jalankan dev server frontend: \033[36mnpm run dev\033[0m");
        $this->line("  3. Buka browser di:             \033[36mhttp://localhost:8000\033[0m");
        $this->line("");

        return 0;
    }

    private function updateEnvVariable(string $envPath, string $key, string $value): void
    {
        $content = file_exists($envPath) ? (string) file_get_contents($envPath) : '';
        $quotedValue = str_contains($value, ' ') ? "\"{$value}\"" : $value;

        if (preg_match("/^{$key}=.*$/m", $content)) {
            $updated = preg_replace("/^{$key}=.*$/m", "{$key}={$quotedValue}", $content);
        } else {
            $updated = rtrim($content) . "\n{$key}={$quotedValue}\n";
        }

        file_put_contents($envPath, $updated);
    }
}
