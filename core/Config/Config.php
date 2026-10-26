<?php

declare(strict_types=1);

namespace Core\Config;

class Config
{
    private static ?self $instance = null;

    /**
     * @var array<string, mixed>
     */
    private array $items = [];

    /**
     * @var array<string, bool>
     */
    private array $loadedFiles = [];

    public function __construct(private ?string $configPath = null)
    {
        $this->configPath = $configPath ?? dirname(__DIR__, 2) . '/config';
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

    /**
     * Ambil item konfigurasi menggunakan dot notation (misal: 'app.name')
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $file = $segments[0];

        $this->loadFile($file);

        $current = $this->items;
        foreach ($segments as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return $default;
            }
            $current = $current[$segment];
        }

        return $current;
    }

    /**
     * Set item konfigurasi di runtime menggunakan dot notation
     */
    public function set(string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $current = &$this->items;

        foreach ($segments as $i => $segment) {
            if ($i === count($segments) - 1) {
                $current[$segment] = $value;
                break;
            }

            if (!isset($current[$segment]) || !is_array($current[$segment])) {
                $current[$segment] = [];
            }

            $current = &$current[$segment];
        }
    }

    /**
     * Muat file konfigurasi jika ada dan belum dimuat
     */
    private function loadFile(string $file): void
    {
        if (isset($this->loadedFiles[$file]) || $this->configPath === null) {
            return;
        }

        $filePath = rtrim($this->configPath, '/\\') . '/' . $file . '.php';
        if (file_exists($filePath)) {
            $data = require $filePath;
            if (is_array($data)) {
                $this->items[$file] = array_replace_recursive($data, $this->items[$file] ?? []);
            }
        }

        $this->loadedFiles[$file] = true;
    }
}
