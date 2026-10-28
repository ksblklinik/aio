<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Console\Command;
use Core\Security\AppKey;

class KeyGenerateCommand extends Command
{
    protected string $signature = 'key:generate';
    protected string $description = 'Set or generate the application encryption key (APP_KEY)';

    public function handle(array $arguments = [], array $options = []): int
    {
        $newKey = AppKey::generateKey();

        if (isset($options['show'])) {
            $this->line("Application Key: {$newKey}");
            return 0;
        }

        $envPath = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '.env';

        if (!file_exists($envPath)) {
            $examplePath = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '.env.example';
            if (file_exists($examplePath)) {
                copy($examplePath, $envPath);
            } else {
                file_put_contents($envPath, "APP_ENV=local\nAPP_DEBUG=true\nAPP_KEY=\n");
            }
        }

        $envContent = (string) file_get_contents($envPath);

        // Check if APP_KEY is already set and not empty
        if (preg_match('/^APP_KEY=(.+)$/m', $envContent, $matches)) {
            $existing = trim($matches[1]);
            if ($existing !== '' && !isset($options['force'])) {
                $this->warn("APP_KEY sudah disetel di .env. Gunakan opsi --force untuk menimpa kunci.");
                $this->line("Kunci saat ini: " . substr($existing, 0, 10) . "...");
                return 1;
            }
        }

        if (preg_match('/^APP_KEY=.*$/m', $envContent)) {
            $updatedContent = preg_replace('/^APP_KEY=.*$/m', "APP_KEY={$newKey}", $envContent);
        } else {
            $updatedContent = $envContent . "\nAPP_KEY={$newKey}\n";
        }

        file_put_contents($envPath, $updatedContent);

        $this->success("Kunci enkripsi aplikasi berhasil dibuat dan disimpan ke .env!");
        $this->line("APP_KEY: {$newKey}");

        return 0;
    }
}
