<?php

declare(strict_types=1);

namespace Tests\Unit;

use Core\Console\Commands\ScheduleRunCommand;
use Core\Console\Kernel;
use Core\Container;
use Core\Foundation\Application;
use Core\Modules\ModuleProvider;
use Core\Schedule\Schedule;
use Core\Schedule\ScheduledTask;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class ScheduleRunnerTest extends TestCase
{
    private Application $app;
    private Schedule $schedule;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app = new Application(dirname(__DIR__, 2));
        $this->schedule = new Schedule();

        $container = $this->app->getContainer();
        $container->instance(Schedule::class, $this->schedule);
    }

    public function test_task_fluent_frequency_and_cron_expressions(): void
    {
        $task1 = $this->schedule->call(fn() => 'task1')->everyMinute();
        $this->assertSame('* * * * *', $task1->getExpression());

        $task2 = $this->schedule->call(fn() => 'task2')->everyFiveMinutes();
        $this->assertSame('*/5 * * * *', $task2->getExpression());

        $task3 = $this->schedule->call(fn() => 'task3')->hourly();
        $this->assertSame('0 * * * *', $task3->getExpression());

        $task4 = $this->schedule->call(fn() => 'task4')->daily();
        $this->assertSame('0 0 * * *', $task4->getExpression());

        $task5 = $this->schedule->call(fn() => 'task5')->dailyAt('14:30');
        $this->assertSame('30 14 * * *', $task5->getExpression());
    }

    public function test_is_due_evaluates_cron_accurately(): void
    {
        $task = new ScheduledTask(fn() => 'ping');
        $task->dailyAt('09:15');

        $timeMatching = new DateTimeImmutable('2026-10-26 09:15:00');
        $timeOtherHour = new DateTimeImmutable('2026-10-26 10:15:00');
        $timeOtherMinute = new DateTimeImmutable('2026-10-26 09:16:00');

        $this->assertTrue($task->isDue($timeMatching));
        $this->assertFalse($task->isDue($timeOtherHour));
        $this->assertFalse($task->isDue($timeOtherMinute));

        // Step expression test: */10 * * * *
        $stepTask = (new ScheduledTask(fn() => 'step'))->everyTenMinutes();
        $this->assertTrue($stepTask->isDue(new DateTimeImmutable('2026-10-26 12:00:00')));
        $this->assertTrue($stepTask->isDue(new DateTimeImmutable('2026-10-26 12:20:00')));
        $this->assertFalse($stepTask->isDue(new DateTimeImmutable('2026-10-26 12:25:00')));
    }

    public function test_task_execution_captures_duration_and_output(): void
    {
        $executed = false;
        $task = new ScheduledTask(function () use (&$executed) {
            $executed = true;
            return 'Task completed successfully';
        });

        $report = $task->run($this->app);

        $this->assertTrue($executed);
        $this->assertTrue($report['success']);
        $this->assertSame('Task completed successfully', $report['output']);
        $this->assertNull($report['error']);
        $this->assertGreaterThanOrEqual(0, $report['duration_ms']);
    }

    public function test_invocable_class_execution(): void
    {
        $handler = new class {
            public function handle(): string
            {
                return 'invocable class handled';
            }
        };

        $container = $this->app->getContainer();
        $className = get_class($handler);
        $container->instance($className, $handler);

        $task = new ScheduledTask($className);
        $report = $task->run($this->app);

        $this->assertTrue($report['success']);
        $this->assertSame('invocable class handled', $report['output']);
    }

    public function test_without_overlapping_mutex_lock(): void
    {
        $lockDir = $this->app->getBasePath() . '/storage/framework';
        if (!is_dir($lockDir)) {
            mkdir($lockDir, 0755, true);
        }

        $task = (new ScheduledTask(fn() => 'long running'))
            ->description('UniqueLongTask')
            ->everyMinute()
            ->withoutOverlapping(60);

        $lockKey = md5('UniqueLongTask* * * * *');
        $lockFile = $lockDir . '/schedule-' . $lockKey . '.lock';

        // Simulate existing active lockfile created 1 minute ago
        file_put_contents($lockFile, '99999');
        touch($lockFile, time() - 60);

        $report = $task->run($this->app);

        $this->assertFalse($report['success']);
        $this->assertStringContainsString('withoutOverlapping', (string) $report['error']);

        // Clean up
        if (file_exists($lockFile)) {
            unlink($lockFile);
        }
    }

    public function test_schedule_due_tasks_and_run_due_tasks(): void
    {
        $count = 0;
        $this->schedule->call(function () use (&$count) {
            $count++;
        })->everyMinute();

        $this->schedule->call(fn() => 'skipped')->dailyAt('03:00');

        // Assume current time is 12:00:00 (not 03:00:00)
        $now = new DateTimeImmutable('2026-10-26 12:00:00');
        $due = $this->schedule->dueTasks($now);

        $this->assertCount(1, $due);

        $results = $this->schedule->runDueTasks($this->app, $now);
        $this->assertCount(1, $results);
        $this->assertTrue($results[0]['success']);
        $this->assertSame(1, $count);
    }

    public function test_cli_schedule_run_command_with_force_now(): void
    {
        $ran = false;
        $this->schedule->call(function () use (&$ran) {
            $ran = true;
        })->daily(); // Not due right now

        $command = new ScheduleRunCommand();
        $command->setContainer($this->app->getContainer());

        ob_start();
        $exitCode = $command->handle([], ['now' => true]);
        $output = ob_get_clean();

        $this->assertSame(0, $exitCode);
        $this->assertTrue($ran);
        $this->assertStringContainsString('Menjalankan 1 task terjadwal', (string) $output);
    }
}
