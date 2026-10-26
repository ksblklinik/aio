<?php

declare(strict_types=1);

namespace Core\Console;

use Core\Container;

abstract class Command
{
    protected string $signature = '';
    protected string $description = '';
    protected ?Container $container = null;

    public function setContainer(Container $container): self
    {
        $this->container = $container;
        return $this;
    }

    public function getSignature(): string
    {
        return $this->signature;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $options
     */
    abstract public function handle(array $arguments = [], array $options = []): int;

    public function line(string $message): void
    {
        echo $message . PHP_EOL;
    }

    public function info(string $message): void
    {
        echo "\033[36mℹ " . $message . "\033[0m" . PHP_EOL;
    }

    public function success(string $message): void
    {
        echo "\033[32m✔ " . $message . "\033[0m" . PHP_EOL;
    }

    public function warn(string $message): void
    {
        echo "\033[33m⚠ " . $message . "\033[0m" . PHP_EOL;
    }

    public function warning(string $message): void
    {
        $this->warn($message);
    }

    public function error(string $message): void
    {
        echo "\033[31m✖ " . $message . "\033[0m" . PHP_EOL;
    }

    /**
     * @param array<int, string> $headers
     * @param array<int, array<int|string, mixed>> $rows
     */
    public function table(array $headers, array $rows): void
    {
        $widths = [];
        foreach ($headers as $i => $header) {
            $widths[$i] = mb_strlen((string) $header);
        }

        foreach ($rows as $row) {
            foreach (array_values($row) as $i => $cell) {
                $cellLen = mb_strlen((string) $cell);
                if (!isset($widths[$i]) || $cellLen > $widths[$i]) {
                    $widths[$i] = $cellLen;
                }
            }
        }

        $sep = '+';
        foreach ($widths as $w) {
            $sep .= str_repeat('-', $w + 2) . '+';
        }

        $this->line($sep);

        $headerLine = '|';
        foreach ($headers as $i => $header) {
            $headerLine .= ' ' . str_pad((string) $header, $widths[$i]) . ' |';
        }
        $this->line("\033[1m" . $headerLine . "\033[0m");
        $this->line($sep);

        foreach ($rows as $row) {
            $rowLine = '|';
            foreach (array_values($row) as $i => $cell) {
                $rowLine .= ' ' . str_pad((string) $cell, $widths[$i]) . ' |';
            }
            $this->line($rowLine);
        }

        $this->line($sep);
    }
}
