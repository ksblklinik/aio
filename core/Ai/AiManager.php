<?php

declare(strict_types=1);

namespace Core\Ai;

use InvalidArgumentException;

class AiManager implements AiClientInterface
{
    /**
     * @var array<string, AiClientInterface>
     */
    private array $drivers = [];

    public function __construct(
        private AiClientInterface $defaultDriver,
        private string $defaultDriverName = 'mock',
        private array $configs = []
    ) {
        $this->drivers[$defaultDriverName] = $defaultDriver;
    }

    public function driver(?string $name = null): AiClientInterface
    {
        $name = $name ?? $this->defaultDriverName;

        if (isset($this->drivers[$name])) {
            return $this->drivers[$name];
        }

        if (!isset($this->configs[$name])) {
            throw new InvalidArgumentException("AI driver [{$name}] is not configured in config/ai.php.");
        }

        $config = $this->configs[$name];
        $driverClass = match ($name) {
            'openai' => OpenAiDriver::class,
            'gemini' => GeminiDriver::class,
            default => MockAiDriver::class,
        };

        $instance = new $driverClass($config);
        $this->drivers[$name] = $instance;

        return $instance;
    }

    public function setDriver(string $name, AiClientInterface $driver): self
    {
        $this->drivers[$name] = $driver;
        return $this;
    }

    public function chat(array $messages, array $options = []): string
    {
        return $this->driver()->chat($messages, $options);
    }

    public function stream(array $messages, callable $onChunk, array $options = []): void
    {
        $this->driver()->stream($messages, $onChunk, $options);
    }

    public function embed(string $text): array
    {
        return $this->driver()->embed($text);
    }

    public function extractJson(string $prompt, array $schema = []): array
    {
        return $this->driver()->extractJson($prompt, $schema);
    }
}
