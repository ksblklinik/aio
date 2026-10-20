<?php

declare(strict_types=1);

namespace Core\Http;

class Session
{
    private bool $isArrayDriver = false;
    private array $data = [];
    private array $flash = [];

    public function startArrayDriver(): void
    {
        $this->isArrayDriver = true;
        $this->data = [];
        $this->flash = [];
        if (!isset($this->data['_token'])) {
            $this->data['_token'] = bin2hex(random_bytes(20));
        }
    }

    public function start(): void
    {
        if ($this->isArrayDriver) {
            return;
        }

        if (session_status() === PHP_SESSION_NONE) {
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'domain' => '',
                'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }

        if (!isset($_SESSION['_token'])) {
            $_SESSION['_token'] = bin2hex(random_bytes(20));
        }

        // Age old flash data
        $this->flash = $_SESSION['_flash'] ?? [];
        $_SESSION['_flash'] = [];
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if ($this->isArrayDriver) {
            return $this->data[$key] ?? $default;
        }
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        if ($this->isArrayDriver) {
            $this->data[$key] = $value;
            return;
        }
        $_SESSION[$key] = $value;
    }

    public function has(string $key): bool
    {
        if ($this->isArrayDriver) {
            return isset($this->data[$key]);
        }
        return isset($_SESSION[$key]);
    }

    public function remove(string $key): void
    {
        if ($this->isArrayDriver) {
            unset($this->data[$key]);
            return;
        }
        unset($_SESSION[$key]);
    }

    public function token(): string
    {
        return (string) $this->get('_token', '');
    }

    public function regenerateToken(): string
    {
        $token = bin2hex(random_bytes(20));
        $this->set('_token', $token);
        return $token;
    }

    public function flash(string $key, mixed $value): void
    {
        if ($this->isArrayDriver) {
            $this->flash[$key] = $value;
            return;
        }
        $_SESSION['_flash'][$key] = $value;
    }

    public function getFlash(string $key, mixed $default = null): mixed
    {
        if ($this->isArrayDriver) {
            return $this->flash[$key] ?? $default;
        }
        return $this->flash[$key] ?? $_SESSION['_flash'][$key] ?? $default;
    }
}
