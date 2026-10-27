<?php

declare(strict_types=1);

namespace Core\Notification\Channels;

class TelegramChannel implements NotificationChannelInterface
{
    /** @var callable|null */
    protected $httpSender;
    protected ?string $botToken;

    public function __construct(?string $botToken = null, ?callable $httpSender = null)
    {
        $this->botToken = $botToken;
        $this->httpSender = $httpSender;
    }

    public function setHttpSender(callable $sender): self
    {
        $this->httpSender = $sender;
        return $this;
    }

    public function send(
        int|string $tenantId,
        mixed $recipient,
        string $title,
        string $message,
        array $options = []
    ): bool {
        $chatId = null;
        if (is_object($recipient) && !empty($recipient->telegram_chat_id)) {
            $chatId = (string) $recipient->telegram_chat_id;
        } elseif (!empty($options['chat_id'])) {
            $chatId = (string) $options['chat_id'];
        } elseif (is_string($recipient) && (str_starts_with($recipient, '@') || is_numeric($recipient))) {
            $chatId = $recipient;
        }

        if ($chatId === null) {
            return false;
        }

        $formattedText = "<b>" . htmlspecialchars($title, ENT_QUOTES) . "</b>\n\n" . htmlspecialchars($message, ENT_QUOTES);
        if (!empty($options['action_url'])) {
            $formattedText .= "\n\n<a href=\"" . htmlspecialchars($options['action_url'], ENT_QUOTES) . "\">Buka Tautan</a>";
        }

        $payload = [
            'chat_id' => $chatId,
            'text' => $formattedText,
            'parse_mode' => 'HTML',
        ];

        // 1. Mock / Custom HTTP Sender
        if ($this->httpSender !== null) {
            return (bool) ($this->httpSender)($payload, $this->botToken);
        }

        // 2. Jika tidak ada bot token terkonfigurasi (local dev mode)
        if (empty($this->botToken)) {
            return true;
        }

        // 3. Native HTTP POST to Telegram Bot API
        $url = "https://api.telegram.org/bot{$this->botToken}/sendMessage";
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => json_encode($payload),
                'timeout' => 5,
                'ignore_errors' => true,
            ],
        ]);

        $res = @file_get_contents($url, false, $context);
        return $res !== false;
    }
}
