<?php

declare(strict_types=1);

namespace Core\Mail;

use RuntimeException;
use Throwable;

class SmtpMailerDriver implements MailerDriverInterface
{
    /**
     * @param array{
     *     host?: string,
     *     port?: int,
     *     username?: ?string,
     *     password?: ?string,
     *     encryption?: ?string,
     *     timeout?: int
     * } $config
     */
    public function __construct(
        protected array $config = []
    ) {
    }

    public function send(Message $message): bool
    {
        $host = (string) ($this->config['host'] ?? '127.0.0.1');
        $port = (int) ($this->config['port'] ?? 25);
        $timeout = (int) ($this->config['timeout'] ?? 10);
        $encryption = strtolower((string) ($this->config['encryption'] ?? ''));

        $prefix = $encryption === 'ssl' ? 'ssl://' : '';
        $socket = @fsockopen($prefix . $host, $port, $errno, $errstr, $timeout);

        if (!$socket) {
            throw new RuntimeException("Could not connect to SMTP host {$host}:{$port} ({$errno}: {$errstr})");
        }

        try {
            $this->readResponse($socket);

            $this->sendCommand($socket, "EHLO " . gethostname());

            if ($encryption === 'tls') {
                $this->sendCommand($socket, "STARTTLS");
                stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                $this->sendCommand($socket, "EHLO " . gethostname());
            }

            $user = $this->config['username'] ?? null;
            $pass = $this->config['password'] ?? null;
            if ($user && $pass) {
                $this->sendCommand($socket, "AUTH LOGIN");
                $this->sendCommand($socket, base64_encode($user));
                $this->sendCommand($socket, base64_encode($pass));
            }

            $from = $message->getFrom() ?? 'noreply@aio.local';
            $this->sendCommand($socket, "MAIL FROM:<{$from}>");

            $recipients = is_array($message->getTo()) ? $message->getTo() : [$message->getTo()];
            foreach ($recipients as $recipient) {
                $this->sendCommand($socket, "RCPT TO:<{$recipient}>");
            }

            $this->sendCommand($socket, "DATA");

            $headers = [];
            $headers[] = "From: {$from}";
            $headers[] = "To: " . implode(', ', $recipients);
            $headers[] = "Subject: " . $message->getSubject();
            $headers[] = "MIME-Version: 1.0";
            if ($message->getHtml()) {
                $headers[] = "Content-Type: text/html; charset=UTF-8";
                $body = $message->getHtml();
            } else {
                $headers[] = "Content-Type: text/plain; charset=UTF-8";
                $body = $message->getText();
            }

            $rawMail = implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.";
            $this->sendCommand($socket, $rawMail);

            $this->sendCommand($socket, "QUIT");

            return true;
        } catch (Throwable $e) {
            return false;
        } finally {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
    }

    protected function sendCommand($socket, string $command): string
    {
        fwrite($socket, $command . "\r\n");
        return $this->readResponse($socket);
    }

    protected function readResponse($socket): string
    {
        $response = '';
        while ($line = fgets($socket, 515)) {
            $response .= $line;
            if (substr($line, 3, 1) === ' ') {
                break;
            }
        }
        return $response;
    }
}
