<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Console\Command;
use Core\Database\Connection;
use Throwable;

class CircuitResetCommand extends Command
{
    protected string $signature = 'circuit:reset';
    protected string $description = 'Reset status circuit breaker ke CLOSED secara manual';

    public function handle(array $arguments = [], array $options = []): int
    {
        $service = $arguments[0] ?? null;

        if (!$service) {
            $this->error("Harap tentukan nama layanan: php aio circuit:reset <service_name>");
            return 1;
        }

        /** @var Connection $db */
        $db = $this->container->get(Connection::class);

        try {
            $existing = $db->selectOne("SELECT id FROM circuit_breakers WHERE service_name = ?", [$service]);
            if (!$existing) {
                $this->warn("Circuit breaker untuk layanan '{$service}' tidak ditemukan.");
                return 1;
            }

            $now = date('Y-m-d H:i:s');
            $db->statement("
                UPDATE circuit_breakers 
                SET state = 'closed', failure_count = 0, success_count = 0, last_error = NULL, last_state_change_at = ?, updated_at = ?
                WHERE service_name = ?
            ", [$now, $now, $service]);

            $this->success("Circuit breaker untuk layanan '{$service}' berhasil di-reset ke status CLOSED.");
            return 0;
        } catch (Throwable $e) {
            $this->error("Gagal me-reset circuit breaker: " . $e->getMessage());
            return 1;
        }
    }
}
