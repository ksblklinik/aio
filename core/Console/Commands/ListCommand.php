<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Console\Command;
use Core\Console\Kernel;

class ListCommand extends Command
{
    protected string $signature = 'list';
    protected string $description = 'Menampilkan daftar seluruh perintah CLI yang tersedia di framework aio';

    public function handle(array $arguments = [], array $options = []): int
    {
        $this->line("\033[1;35m       _         \033[0m");
        $this->line("\033[1;35m  __ _(_)___     \033[0m\033[1;37maio Full-Stack SaaS Framework\033[0m");
        $this->line("\033[1;35m / _` | / _ \    \033[0m\033[90mv1.0.0 (PHP " . PHP_VERSION . ")\033[0m");
        $this->line("\033[1;35m \\__,_|_\\___/    \033[0m");
        $this->line("");
        $this->line("\033[33mPenggunaan:\033[0m");
        $this->line("  php aio <perintah> [argumen] [opsi]");
        $this->line("");
        $this->line("\033[33mPerintah yang Tersedia:\033[0m");

        /** @var Kernel $kernel */
        $kernel = $this->container->get(Kernel::class);
        $commands = $kernel->getCommands();

        $rows = [];
        foreach ($commands as $name => $cmd) {
            $rows[] = [
                'Perintah' => "\033[36m" . $name . "\033[0m",
                'Deskripsi' => $cmd->getDescription(),
            ];
        }

        $this->table(['Perintah', 'Deskripsi'], $rows);

        return 0;
    }
}
