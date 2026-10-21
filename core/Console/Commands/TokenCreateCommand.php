<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use App\Modules\Workspaces\Models\User;
use Core\Auth\ApiTokenService;
use Core\Console\Command;
use Core\Database\Connection;

class TokenCreateCommand extends Command
{
    protected string $signature = 'token:create';
    protected string $description = 'Membuat Personal Access Token baru untuk integrasi REST API';

    public function handle(array $arguments = [], array $options = []): int
    {
        $email = $arguments[0] ?? null;
        $name = $arguments[1] ?? ($options['name'] ?? null);

        if (!$email || !$name) {
            $this->error("Argumen email dan nama token wajib diisi.");
            $this->line("Contoh: \033[36mphp aio token:create alice@acme.com \"Zapier Webhook\" --tenant=1\033[0m");
            return 1;
        }

        /** @var Connection $db */
        $db = $this->container->get(Connection::class);
        $user = User::findByEmail($db, (string) $email);

        if (!$user) {
            $this->error("Pengguna dengan email [{$email}] tidak ditemukan.");
            return 1;
        }

        $tenantId = isset($options['tenant']) ? (int) $options['tenant'] : null;
        if ($tenantId === null) {
            $tenants = $user->getTenants($db);
            if (empty($tenants)) {
                $this->error("Pengguna [{$email}] belum terdaftar dalam workspace manapun.");
                return 1;
            }
            $tenantId = (int) $tenants[0]['id'];
        }

        $abilities = ['*'];
        if (isset($options['abilities'])) {
            $abilities = array_map('trim', explode(',', (string) $options['abilities']));
        }

        $days = isset($options['days']) ? (int) $options['days'] : 90;
        $expiresAt = $days > 0 ? date('Y-m-d H:i:s', time() + ($days * 86400)) : null;

        /** @var ApiTokenService $tokenService */
        $tokenService = $this->container->get(ApiTokenService::class);
        $created = $tokenService->createToken($tenantId, (int) $user->id, (string) $name, $abilities, $expiresAt);

        $this->success("Personal Access Token berhasil dibuat!");
        $this->line("  - Token ID:     {$created['id']}");
        $this->line("  - Nama Token:   {$created['name']}");
        $this->line("  - Workspace ID: {$tenantId}");
        $this->line("  - Pemilik:      {$user->name} <{$user->email}>");
        $this->line("  - Hak Akses:    " . implode(', ', $abilities));
        $this->line("  - Kedaluwarsa:  " . ($expiresAt ?: 'Tidak terbatas'));
        $this->line("");
        $this->warn("Simpan token ini di tempat aman. Token tidak akan ditampilkan lagi:");
        $this->line("\033[1;32m" . $created['plain_token'] . "\033[0m");

        return 0;
    }
}
