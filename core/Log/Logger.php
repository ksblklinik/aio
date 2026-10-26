<?php

declare(strict_types=1);

namespace Core\Log;

class Logger
{
    private static ?self $instance = null;

    public function __construct(private ?string $logPath = null)
    {
        $this->logPath = $logPath ?? dirname(__DIR__, 2) . '/storage/logs';
        $this->ensureDirectoryExists();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function setInstance(?self $instance): void
    {
        self::$instance = $instance;
    }

    public function emergency(string $message, array $context = []): void
    {
        $this->log('EMERGENCY', $message, $context);
    }

    public function alert(string $message, array $context = []): void
    {
        $this->log('ALERT', $message, $context);
    }

    public function critical(string $message, array $context = []): void
    {
        $this->log('CRITICAL', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log('ERROR', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log('WARNING', $message, $context);
    }

    public function notice(string $message, array $context = []): void
    {
        $this->log('NOTICE', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log('INFO', $message, $context);
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log('DEBUG', $message, $context);
    }

    public function log(string $level, string $message, array $context = []): void
    {
        $level = strtoupper($level);
        $timestamp = date('Y-m-d H:i:s');
        $contextString = !empty($context) ? ' ' . json_encode($context, JSON_UNESCAPED_SLASHES) : '';

        $logLine = "[{$timestamp}] [{$level}]: {$message}{$contextString}" . PHP_EOL;

        $fileName = $this->logPath . '/aio-' . date('Y-m-d') . '.log';
        file_put_contents($fileName, $logLine, FILE_APPEND | LOCK_EX);
    }

    private function ensureDirectoryExists(): void
    {
        if ($this->logPath !== null && !is_dir($this->logPath)) {
            mkdir($this->logPath, 0777, true);
        }
    }
}
