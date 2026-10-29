<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Console\Command;
use Core\Database\Connection;

class CreateSuperadminCommand extends Command
{
    protected string $signature = 'user:create-superadmin';
    protected string $description = 'Membuat akun Platform Superadmin baru atau mempromosikan akun yang sudah ada';

    public function handle(array $arguments = [], array $options = []): int
    {
        $email = $options['email'] ?? $arguments[0] ?? null;
        $name = $options['name'] ?? 'Platform Superadmin';
        $password = $options['password'] ?? 'superadmin123';

        if (!$email) {
            $this->error("Alamat email wajib diberikan. Contoh: php aio user:create-superadmin root@aio.local --password=rahasia");
            return 1;
        }

        /** @var Connection $db */
        $db = $this->container->get(Connection::class);

        $existing = $db->selectOne("SELECT id, name, email FROM users WHERE email = ?", [trim(strtolower((string) $email))]);

        if ($existing) {
            $db->statement(
                "UPDATE users SET is_superadmin = 1 WHERE id = ?",
                [(int) $existing['id']]
            );
            $this->success("Pengguna [{$existing['name']} <{$existing['email']}>] berhasil dipromosikan menjadi Platform Superadmin.");
            return 0;
        }

        $passwordHash = password_hash((string) $password, PASSWORD_DEFAULT);
        $userId = $db->insert('users', [
            'name' => $name,
            'email' => strtolower((string) $email),
            'password_hash' => $passwordHash,
            'is_superadmin' => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->success("Akun Platform Superadmin baru berhasil dibuat!");
        $this->line("  - ID:       {$userId}");
        $this->line("  - Nama:     {$name}");
        $this->line("  - Email:    {$email}");
        $this->line("  - Password: {$password}");
        return 0;
    }
}
