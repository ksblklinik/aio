<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Core\Console\Commands\AppInstallCommand;
use Core\Console\Kernel;
use Core\Container;
use Core\Database\Connection;
use Core\Database\Migrator;
use Core\Modules\ModuleManager;

class AppInstallCommandTest extends TestCase
{
    private string $tempDir;
    private Connection $db;
    private Container $container;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/aio_install_test_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0777, true);

        $dbFile = $this->tempDir . '/test_install.sqlite';
        $this->db = new Connection("sqlite:{$dbFile}");

        $this->container = Container::getInstance();
        $this->container->singleton(Connection::class, fn() => $this->db);
        $this->container->singleton(ModuleManager::class, fn() => new ModuleManager($this->db->getPdo(), dirname(__DIR__, 2) . '/app/Modules'));
    }

    protected function tearDown(): void
    {
        // Bersihkan temp directory
        $files = glob($this->tempDir . '/*');
        if ($files) {
            foreach ($files as $f) {
                if (is_file($f)) {
                    @unlink($f);
                }
            }
        }
        @rmdir($this->tempDir);
    }

    public function test_app_install_signature_and_description(): void
    {
        $command = new AppInstallCommand();
        $this->assertStringStartsWith('app:install', $command->getSignature());
        $this->assertNotEmpty($command->getDescription());
    }

    public function test_app_install_non_interactive_workflow(): void
    {
        $command = new AppInstallCommand();
        $command->setContainer($this->container);

        // Buat file .env.example di root tempDir untuk disalin
        $envExamplePath = dirname(__DIR__, 2) . '/.env.example';
        $this->assertFileExists($envExamplePath);

        ob_start();
        $exitCode = $command->handle([], [
            'non-interactive' => true,
            'no-seed' => true,
            'app-name' => 'Klinik Terpadu AIO',
            'admin-name' => 'Direktur Medis',
            'admin-email' => 'direktur@klinik.local',
            'admin-password' => 'rahasiaMedis123',
        ]);
        $output = (string) ob_get_clean();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('INSTALASI SELESAI', $output);
        $this->assertStringContainsString('direktur@klinik.local', $output);

        // Verifikasi skema database terpasang
        $this->assertTrue($this->db->hasColumn('users', 'id'));
        $this->assertTrue($this->db->hasColumn('tenants', 'id'));

        // Verifikasi superadmin terdaftar
        $admin = $this->db->selectOne("SELECT * FROM users WHERE email = ?", ['direktur@klinik.local']);
        $this->assertNotNull($admin);
        $this->assertSame('Direktur Medis', $admin['name']);
        $this->assertSame(1, (int) $admin['is_superadmin']);
        $this->assertTrue(password_verify('rahasiaMedis123', (string) $admin['password_hash']));
    }

    public function test_app_install_with_seeding_and_existing_user_promotion(): void
    {
        $command = new AppInstallCommand();
        $command->setContainer($this->container);

        // Migrasikan database terlebih dahulu
        $migrator = new Migrator($this->db);
        $migrator->runAllMigrations();

        // Buat user biasa non-superadmin
        $this->db->insert('users', [
            'id' => 99,
            'name' => 'Calon Superadmin',
            'email' => 'promoted@aio.local',
            'password_hash' => password_hash('oldpass', PASSWORD_BCRYPT),
            'is_superadmin' => 0,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        ob_start();
        $exitCode = $command->handle([], [
            'non-interactive' => true,
            'admin-email' => 'promoted@aio.local',
        ]);
        $output = (string) ob_get_clean();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('dipromosikan menjadi Platform Superadmin', $output);

        // Verifikasi status promosi
        $admin = $this->db->selectOne("SELECT is_superadmin FROM users WHERE email = ?", ['promoted@aio.local']);
        $this->assertNotNull($admin);
        $this->assertSame(1, (int) $admin['is_superadmin']);

        // Verifikasi tenant default seeded
        $tenant = $this->db->selectOne("SELECT * FROM tenants WHERE slug = 'acme'");
        $this->assertNotNull($tenant);
    }

    public function test_app_install_with_no_seed_skips_seeding(): void
    {
        $command = new AppInstallCommand();
        $command->setContainer($this->container);

        ob_start();
        $exitCode = $command->handle([], [
            'non-interactive' => true,
            'no-seed' => true,
            'admin-email' => 'sole_admin@aio.local',
        ]);
        $output = (string) ob_get_clean();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Melewati seeding data awal', $output);

        // Verifikasi bahwa user demo (misal Sarah Smith) tidak di-seed
        $sarah = $this->db->selectOne("SELECT * FROM users WHERE email = 'sarah@acme.com'");
        $this->assertNull($sarah, 'User demo Sarah tidak boleh ada saat opsi --no-seed digunakan');
    }
}
