<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Console\Command;
use Core\Foundation\Application;
use Core\Schedule\Schedule;

class ScheduleRunCommand extends Command
{
    protected string $signature = 'schedule:run';
    protected string $description = 'Jalankan scheduled tasks yang jatuh tempo saat ini';

    public function handle(array $arguments = [], array $options = []): int
    {
        /** @var Schedule $schedule */
        $schedule = $this->container->get(Schedule::class);
        $app = Application::getInstance();

        $forceNow = isset($options['now']) && $options['now'] !== false;

        $tasks = $forceNow ? $schedule->tasks() : $schedule->dueTasks();

        $timestamp = date('Y-m-d H:i:s');

        if (empty($tasks)) {
            $this->line("Tidak ada scheduled task yang jatuh tempo pada [{$timestamp}].");
            return 0;
        }

        $this->info("Menjalankan " . count($tasks) . " task terjadwal pada [{$timestamp}]...");

        $hasError = false;

        foreach ($tasks as $task) {
            $desc = $task->getDescription();
            $expr = $task->getExpression();

            $this->line("Running: [{$desc}] ({$expr})");
            $report = $task->run($app);

            if ($report['success']) {
                $outputSnippet = $report['output'] ? " Output: " . trim($report['output']) : '';
                $this->info("  ✓ Selesai dalam {$report['duration_ms']}ms.{$outputSnippet}");
            } else {
                $hasError = true;
                $this->error("  ✗ Gagal dalam {$report['duration_ms']}ms: {$report['error']}");
            }
        }

        return $hasError ? 1 : 0;
    }
}
