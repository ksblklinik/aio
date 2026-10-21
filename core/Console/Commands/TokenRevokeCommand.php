<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Auth\ApiTokenService;
use Core\Console\Command;

class TokenRevokeCommand extends Command
{
    protected string $signature = 'token:revoke';
    protected string $description = 'Mencabut (revoke) API token berdasarkan ID';

    public function handle(array $arguments = [], array $options = []): int
    {
        $id = $arguments[0] ?? null;

        if (!$id || !is_numeric($id)) {
            $this->error("ID token wajib diisi berupa angka. Contoh: php aio token:revoke 1");
            return 1;
        }

        /** @var ApiTokenService $tokenService */
        $tokenService = $this->container->get(ApiTokenService::class);
        $revoked = $tokenService->revokeToken((int) $id);

        if ($revoked) {
            $this->success("API Token ID #{$id} berhasil dicabut.");
            return 0;
        }

        $this->warn("Token ID #{$id} tidak ditemukan.");
        return 1;
    }
}
