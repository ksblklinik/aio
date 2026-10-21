<?php

declare(strict_types=1);

namespace Core\Console;

use Core\Container;
use Throwable;

class Kernel
{
    private Container $container;

    /**
     * @var array<string, Command>
     */
    private array $commands = [];

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    public function register(Command $command): self
    {
        $command->setContainer($this->container);
        $signature = $command->getSignature();
        $name = explode(' ', $signature)[0];
        $this->commands[$name] = $command;
        return $this;
    }

    /**
     * @return array<string, Command>
     */
    public function getCommands(): array
    {
        return $this->commands;
    }

    /**
     * @param array<int, string> $argv
     */
    public function handle(array $argv): int
    {
        // Hilangkan nama script (argv[0])
        array_shift($argv);

        $commandName = array_shift($argv) ?? 'list';

        if ($commandName === '--help' || $commandName === '-h') {
            $commandName = 'list';
        }

        if (!isset($this->commands[$commandName])) {
            echo "\033[31m✖ Perintah '{$commandName}' tidak dikenali.\033[0m" . PHP_EOL;
            echo "Ketik \033[36mphp aio list\033[0m untuk melihat seluruh perintah yang tersedia." . PHP_EOL;
            return 1;
        }

        [$arguments, $options] = $this->parseInput($argv);

        try {
            return $this->commands[$commandName]->handle($arguments, $options);
        } catch (Throwable $e) {
            echo "\033[31m✖ Terjadi kesalahan saat mengeksekusi perintah [{$commandName}]:\033[0m" . PHP_EOL;
            echo "\033[33m" . $e->getMessage() . "\033[0m" . PHP_EOL;
            echo "\033[90m" . $e->getFile() . ':' . $e->getLine() . "\033[0m" . PHP_EOL;
            return 1;
        }
    }

    /**
     * @param array<int, string> $tokens
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function parseInput(array $tokens): array
    {
        $arguments = [];
        $options = [];
        $argIndex = 0;

        foreach ($tokens as $token) {
            if (str_starts_with($token, '--')) {
                $opt = substr($token, 2);
                if (str_contains($opt, '=')) {
                    [$key, $val] = explode('=', $opt, 2);
                    $options[$key] = $val;
                } else {
                    $options[$opt] = true;
                }
            } elseif (str_starts_with($token, '-')) {
                $opt = substr($token, 1);
                $options[$opt] = true;
            } else {
                $arguments[$argIndex] = $token;
                $argIndex++;
            }
        }

        return [$arguments, $options];
    }
}
