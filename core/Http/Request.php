<?php

declare(strict_types=1);

namespace Core\Http;

class Request
{
    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $queryParams
     * @param array<string, mixed> $bodyParams
     * @param array<string, mixed> $serverParams
     * @param array<string, mixed> $cookieParams
     * @param array<string, UploadedFile> $files
     */
    public function __construct(
        private string $method = 'GET',
        private string $uri = '/',
        private array $headers = [],
        private array $queryParams = [],
        private array $bodyParams = [],
        private array $serverParams = [],
        private array $cookieParams = [],
        private array $attributes = [],
        private array $files = []
    ) {
        $this->headers = array_change_key_case($this->headers, CASE_LOWER);
        $this->method = strtoupper($this->method);

        // HTTP Method Spoofing (_method atau header X-HTTP-Method-Override)
        if ($this->method === 'POST') {
            $spoofed = null;
            if (isset($this->bodyParams['_method'])) {
                $spoofed = strtoupper((string) $this->bodyParams['_method']);
            } elseif (isset($this->headers['x-http-method-override'])) {
                $spoofed = strtoupper((string) $this->headers['x-http-method-override']);
            }

            if ($spoofed !== null && in_array($spoofed, ['PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'], true)) {
                $this->method = $spoofed;
            }
        }
    }

    /**
     * Tangkap request aktif dari variabel global PHP ($_SERVER, $_GET, $_POST, $_FILES, php://input)
     */
    public static function capture(): self
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headerName = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$headerName] = (string) $value;
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'])) {
                $headerName = strtolower(str_replace('_', '-', $key));
                $headers[$headerName] = (string) $value;
            }
        }

        $queryParams = $_GET;
        $bodyParams = $_POST;

        $contentType = $headers['content-type'] ?? '';
        if (str_contains($contentType, 'application/json')) {
            $rawInput = file_get_contents('php://input');
            if ($rawInput) {
                $decoded = json_decode($rawInput, true);
                if (is_array($decoded)) {
                    $bodyParams = array_merge($bodyParams, $decoded);
                }
            }
        }

        $files = [];
        if (!empty($_FILES)) {
            foreach ($_FILES as $key => $fileData) {
                if (isset($fileData['name'], $fileData['tmp_name'])) {
                    $files[$key] = new UploadedFile(
                        name: (string) $fileData['name'],
                        type: (string) ($fileData['type'] ?? 'application/octet-stream'),
                        tmpName: (string) $fileData['tmp_name'],
                        error: (int) ($fileData['error'] ?? UPLOAD_ERR_OK),
                        size: (int) ($fileData['size'] ?? 0)
                    );
                }
            }
        }

        return new self(
            method: strtoupper($method),
            uri: $uri,
            headers: $headers,
            queryParams: $queryParams,
            bodyParams: $bodyParams,
            serverParams: $_SERVER,
            cookieParams: $_COOKIE,
            files: $files
        );
    }

    public function method(): string
    {
        return $this->method;
    }

    public function isMethod(string $method): bool
    {
        return strtoupper($this->method) === strtoupper($method);
    }

    public function uri(): string
    {
        return $this->uri;
    }

    public function path(): string
    {
        $path = parse_url($this->uri, PHP_URL_PATH);
        return $path ?: '/';
    }

    public function header(string $name, ?string $default = null): ?string
    {
        $normalized = strtolower($name);
        return $this->headers[$normalized] ?? $default;
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headers[strtolower($name)]);
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    public function query(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->queryParams;
        }
        return $this->queryParams[$key] ?? $default;
    }

    public function input(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return array_merge($this->queryParams, $this->bodyParams);
        }
        return $this->bodyParams[$key] ?? $this->queryParams[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->bodyParams) || array_key_exists($key, $this->queryParams);
    }

    public function filled(string $key): bool
    {
        return $this->has($key) && trim((string) $this->input($key)) !== '';
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return array_merge($this->queryParams, $this->bodyParams);
    }

    public function file(string $key): ?UploadedFile
    {
        return $this->files[$key] ?? null;
    }

    public function hasFile(string $key): bool
    {
        return isset($this->files[$key]) && $this->files[$key] instanceof UploadedFile;
    }

    /**
     * @return array<string, UploadedFile>
     */
    public function files(): array
    {
        return $this->files;
    }

    public function isJson(): bool
    {
        $contentType = $this->header('content-type', '');
        return str_contains($contentType, 'application/json');
    }

    public function wantsJson(): bool
    {
        $accept = $this->header('accept', '');
        return str_contains($accept, 'application/json');
    }

    public function isInertia(): bool
    {
        return $this->header('x-inertia') === 'true';
    }

    public function ip(): string
    {
        return (string) ($this->serverParams['REMOTE_ADDR'] ?? '127.0.0.1');
    }

    public function userAgent(): ?string
    {
        return $this->header('user-agent');
    }

    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function getAttribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }
}
