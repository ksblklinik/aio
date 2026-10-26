<?php

declare(strict_types=1);

namespace Core\Schedule;

use Core\Foundation\Application;
use DateTimeInterface;

class Schedule
{
    /**
     * @var array<int, ScheduledTask>
     */
    protected array $tasks = [];

    /**
     * Register a new callback or invocable class scheduled task.
     *
     * @param callable|string $callback
     * @param array<int, mixed> $parameters
     */
    public function call(mixed $callback, array $parameters = []): ScheduledTask
    {
        $task = new ScheduledTask($callback, $parameters);
        $this->tasks[] = $task;
        return $task;
    }

    /**
     * Register a shell or CLI command scheduled task.
     */
    public function command(string $command): ScheduledTask
    {
        return $this->call($command)->description("Command: {$command}");
    }

    /**
     * Get all registered scheduled tasks.
     *
     * @return array<int, ScheduledTask>
     */
    public function tasks(): array
    {
        return $this->tasks;
    }

    /**
     * Filter tasks that are due to execute at the given timestamp.
     *
     * @return array<int, ScheduledTask>
     */
    public function dueTasks(?DateTimeInterface $time = null): array
    {
        return array_values(array_filter(
            $this->tasks,
            fn(ScheduledTask $task) => $task->isDue($time)
        ));
    }

    /**
     * Run all tasks that are currently due and collect execution reports.
     *
     * @return array<int, array{description: string, expression: string, success: bool, output: ?string, error: ?string, duration_ms: float}>
     */
    public function runDueTasks(Application $app, ?DateTimeInterface $time = null): array
    {
        $due = $this->dueTasks($time);
        $results = [];

        foreach ($due as $task) {
            $report = $task->run($app);
            $results[] = [
                'description' => $task->getDescription(),
                'expression' => $task->getExpression(),
                'success' => $report['success'],
                'output' => $report['output'],
                'error' => $report['error'],
                'duration_ms' => $report['duration_ms'],
            ];
        }

        return $results;
    }
}
