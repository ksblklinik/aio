<?php

declare(strict_types=1);

namespace Core\Schedule;

use Core\Foundation\Application;
use DateTimeInterface;
use Throwable;

class ScheduledTask
{
    protected string $expression = '* * * * *';
    protected ?string $description = null;
    protected bool $withoutOverlapping = false;
    protected int $mutexExpiresMinutes = 120;

    /**
     * @param callable|string $callback Callable closure, class string, or CLI command string
     * @param array<int, mixed> $parameters
     */
    public function __construct(
        protected mixed $callback,
        protected array $parameters = []
    ) {
    }

    public function cron(string $expression): self
    {
        $this->expression = trim($expression);
        return $this;
    }

    public function everyMinute(): self
    {
        return $this->cron('* * * * *');
    }

    public function everyFiveMinutes(): self
    {
        return $this->cron('*/5 * * * *');
    }

    public function everyTenMinutes(): self
    {
        return $this->cron('*/10 * * * *');
    }

    public function everyFifteenMinutes(): self
    {
        return $this->cron('*/15 * * * *');
    }

    public function everyThirtyMinutes(): self
    {
        return $this->cron('*/30 * * * *');
    }

    public function hourly(): self
    {
        return $this->cron('0 * * * *');
    }

    public function daily(): self
    {
        return $this->cron('0 0 * * *');
    }

    public function dailyAt(string $time): self
    {
        $parts = explode(':', $time);
        $hour = (int) ($parts[0] ?? 0);
        $minute = (int) ($parts[1] ?? 0);

        return $this->cron("{$minute} {$hour} * * *");
    }

    public function weekly(): self
    {
        return $this->cron('0 0 * * 0');
    }

    public function monthly(): self
    {
        return $this->cron('0 0 1 * *');
    }

    public function description(string $description): self
    {
        $this->description = $description;
        return $this;
    }

    public function getDescription(): string
    {
        if ($this->description !== null) {
            return $this->description;
        }

        if (is_string($this->callback)) {
            return $this->callback;
        }

        return 'Closure Task';
    }

    public function withoutOverlapping(int $expiresMinutes = 120): self
    {
        $this->withoutOverlapping = true;
        $this->mutexExpiresMinutes = $expiresMinutes;
        return $this;
    }

    public function getExpression(): string
    {
        return $this->expression;
    }

    /**
     * Determine if the scheduled task is due to run based on current time.
     */
    public function isDue(?DateTimeInterface $time = null): bool
    {
        $dt = $time ?? new \DateTimeImmutable('now');

        $minute = (int) $dt->format('i');
        $hour = (int) $dt->format('G');
        $dayOfMonth = (int) $dt->format('j');
        $month = (int) $dt->format('n');
        $dayOfWeek = (int) $dt->format('w'); // 0 (Sunday) to 6 (Saturday)

        $segments = preg_split('/\s+/', $this->expression);
        if (!$segments || count($segments) !== 5) {
            return false;
        }

        return $this->matchesSegment($segments[0], $minute, 0, 59)
            && $this->matchesSegment($segments[1], $hour, 0, 23)
            && $this->matchesSegment($segments[2], $dayOfMonth, 1, 31)
            && $this->matchesSegment($segments[3], $month, 1, 12)
            && $this->matchesSegment($segments[4], $dayOfWeek, 0, 6);
    }

    /**
     * Run the scheduled task.
     *
     * @return array{success: bool, output: ?string, error: ?string, duration_ms: float}
     */
    public function run(Application $app): array
    {
        $lockFile = null;
        if ($this->withoutOverlapping) {
            $lockKey = md5($this->getDescription() . $this->expression);
            $lockDir = $app->getBasePath() . '/storage/framework';
            if (!is_dir($lockDir)) {
                mkdir($lockDir, 0755, true);
            }
            $lockFile = $lockDir . '/schedule-' . $lockKey . '.lock';

            if (file_exists($lockFile)) {
                $mtime = filemtime($lockFile) ?: 0;
                if ((time() - $mtime) < ($this->mutexExpiresMinutes * 60)) {
                    return [
                        'success' => false,
                        'output' => null,
                        'error' => 'Task skipped: another instance is currently running (withoutOverlapping).',
                        'duration_ms' => 0.0,
                    ];
                }
            }
            file_put_contents($lockFile, (string) getmypid());
        }

        $startTime = microtime(true);
        $output = null;
        $error = null;
        $success = true;

        try {
            if (is_callable($this->callback)) {
                $result = call_user_func_array($this->callback, array_merge([$app], $this->parameters));
                $output = is_string($result) ? $result : null;
            } elseif (is_string($this->callback) && class_exists($this->callback)) {
                $instance = $app->getContainer()->get($this->callback);
                if (method_exists($instance, '__invoke')) {
                    $result = $instance($app, ...$this->parameters);
                } elseif (method_exists($instance, 'handle')) {
                    $result = $instance->handle($app, ...$this->parameters);
                } else {
                    throw new \RuntimeException("Scheduled class {$this->callback} must have handle() or __invoke() method.");
                }
                $output = is_string($result) ? $result : null;
            } elseif (is_string($this->callback)) {
                // Execute CLI command via shell/PHP
                ob_start();
                passthru($this->callback);
                $output = ob_get_clean() ?: null;
            }
        } catch (Throwable $e) {
            $success = false;
            $error = $e->getMessage();
        } finally {
            if ($lockFile && file_exists($lockFile)) {
                @unlink($lockFile);
            }
        }

        $durationMs = round((microtime(true) - $startTime) * 1000, 2);

        return [
            'success' => $success,
            'output' => $output,
            'error' => $error,
            'duration_ms' => $durationMs,
        ];
    }

    /**
     * Check if a single cron expression segment matches the current numeric value.
     */
    protected function matchesSegment(string $segment, int $current, int $min, int $max): bool
    {
        if ($segment === '*') {
            return true;
        }

        // Step values: */5 or 1-10/2
        if (str_contains($segment, '/')) {
            [$range, $step] = explode('/', $segment, 2);
            $stepInt = (int) $step;
            if ($stepInt <= 0) {
                return false;
            }

            if ($range === '*') {
                return ($current - $min) % $stepInt === 0;
            }

            if (str_contains($range, '-')) {
                [$rStart, $rEnd] = explode('-', $range, 2);
                $start = (int) $rStart;
                $end = (int) $rEnd;
                if ($current < $start || $current > $end) {
                    return false;
                }
                return ($current - $start) % $stepInt === 0;
            }
        }

        // Multiple values: 1,3,5
        if (str_contains($segment, ',')) {
            $values = explode(',', $segment);
            foreach ($values as $val) {
                if ($this->matchesSegment(trim($val), $current, $min, $max)) {
                    return true;
                }
            }
            return false;
        }

        // Range: 1-5
        if (str_contains($segment, '-')) {
            [$rStart, $rEnd] = explode('-', $segment, 2);
            return $current >= (int) $rStart && $current <= (int) $rEnd;
        }

        // Exact match
        return (int) $segment === $current;
    }
}
