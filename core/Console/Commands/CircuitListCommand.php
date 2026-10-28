<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Console\Command;
use Core\Database\Connection;
use Throwable;

class CircuitListCommand extends Command
{
    protected string $signature = 'circuit:list';
    protected string $description = 'Daftar seluruh circuit breaker yang terdaftar dan status fail-fast masing-masing';

    public function handle(array $arguments = [], array $options = []): int
    {
        /** @var Connection $db */
        $db = $this->container->get(Connection::class);

        try {
            $breakers = $db->selectAll("SELECT * FROM circuit_breakers ORDER BY service_name ASC");

            if (empty($breakers)) {
                $this->info("Belum ada circuit breaker yang terdaftar dalam basis data.");
                return 0;
            }

            $this->info("Daftar Circuit Breaker Layanan:");
            $this->line(str_repeat('-', 85));
            $this->line(sprintf(
                "%-25s | %-12s | %-8s | %-8s | %-12s | %s",
                "Nama Layanan",
                "Status",
                "Gagal",
                "Ambang",
                "Cooldown",
                "Error Terakhir"
            ));
            $this->line(str_repeat('-', 85));

            foreach ($breakers as $b) {
                $statusColor = match ($b['state']) {
                    'closed' => "\033[32mCLOSED\033[0m",
                    'open' => "\033[31mOPEN\033[0m",
                    default => "\033[33mHALF_OPEN\033[0m",
                };

                $errorSnippet = $b['last_error'] ? mb_substr((string) $b['last_error'], 0, 25) . '...' : '-';

                $this->line(sprintf(
                    "%-25s | %-21s | %-8d | %-8d | %-12s | %s",
                    $b['service_name'],
                    $statusColor,
                    $b['failure_count'],
                    $b['failure_threshold'],
                    $b['cooldown_seconds'] . 's',
                    $errorSnippet
                ));
            }

            $this->line(str_repeat('-', 85));
            return 0;
        } catch (Throwable $e) {
            $this->error("Gagal memuat daftar circuit breaker: " . $e->getMessage());
            return 1;
        }
    }
}
