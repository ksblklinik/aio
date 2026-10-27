<?php

declare(strict_types=1);

namespace Core\Http;

class Response
{
    /**
     * @var (callable(): void)|null
     */
    private $streamCallback = null;

    /**
     * @param array<string, string> $headers
     * @param (callable(): void)|null $streamCallback
     */
    public function __construct(
        private string $body = '',
        private int $statusCode = 200,
        private array $headers = [],
        ?callable $streamCallback = null
    ) {
        $this->streamCallback = $streamCallback;
    }

    public static function json(mixed $data, int $statusCode = 200, array $headers = []): self
    {
        $headers['Content-Type'] = 'application/json';
        $body = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return new self($body ?: '{}', $statusCode, $headers);
    }

    public static function html(string $html, int $statusCode = 200, array $headers = []): self
    {
        $headers['Content-Type'] = 'text/html; charset=UTF-8';
        return new self($html, $statusCode, $headers);
    }

    public static function redirect(string $url, int $statusCode = 302, array $headers = []): self
    {
        $headers['Location'] = $url;
        return new self('', $statusCode, $headers);
    }

    /**
     * Buat streaming response menggunakan callback generator / loop
     *
     * @param callable(): void $callback
     * @param array<string, string> $headers
     */
    public static function stream(callable $callback, int $statusCode = 200, array $headers = []): self
    {
        return new self('', $statusCode, $headers, $callback);
    }

    /**
     * Buat Server-Sent Events (SSE) streaming response untuk real-time update
     *
     * @param callable(callable(string|array, ?string, ?string): void): void $callback
     * @param array<string, string> $headers
     */
    public static function sse(callable $callback, array $headers = []): self
    {
        $defaultHeaders = [
            'Content-Type' => 'text/event-stream; charset=UTF-8',
            'Cache-Control' => 'no-cache, no-transform',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ];

        $allHeaders = array_replace($defaultHeaders, $headers);

        $sseRunner = function () use ($callback) {
            $sender = function (string|array $data, ?string $event = null, ?string $id = null): void {
                if ($id !== null) {
                    echo "id: {$id}\n";
                }
                if ($event !== null) {
                    echo "event: {$event}\n";
                }

                $payload = is_array($data) ? json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : $data;
                // Multiple lines data support
                $lines = explode("\n", (string) $payload);
                foreach ($lines as $line) {
                    echo "data: {$line}\n";
                }
                echo "\n";

                if (php_sapi_name() !== 'cli') {
                    if (function_exists('ob_flush') && ob_get_level() > 0) {
                        @ob_flush();
                    }
                    flush();
                }
            };

            $callback($sender);
        };

        return new self('', 200, $allHeaders, $sseRunner);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function setStatusCode(int $statusCode): self
    {
        $this->statusCode = $statusCode;
        return $this;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getContent(): string
    {
        return $this->body;
    }

    public function setBody(string $body): self
    {
        $this->body = $body;
        return $this;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getHeader(string $name, ?string $default = null): ?string
    {
        foreach ($this->headers as $headerName => $val) {
            if (strcasecmp($headerName, $name) === 0) {
                return $val;
            }
        }
        return $default;
    }

    public function setHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function header(string $name, string $value): self
    {
        return $this->setHeader($name, $value);
    }

    public function getStreamCallback(): ?callable
    {
        return $this->streamCallback;
    }

    /**
     * Kirim response ke output buffer browser / client
     */
    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->statusCode);
            foreach ($this->headers as $name => $value) {
                header("{$name}: {$value}");
            }
        }

        if ($this->streamCallback !== null) {
            ($this->streamCallback)();
            return;
        }

        echo $this->body;
    }
}

