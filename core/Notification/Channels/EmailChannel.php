<?php

declare(strict_types=1);

namespace Core\Notification\Channels;

use Core\Mail\MailerDriverInterface;
use Core\Mail\Message;

class EmailChannel implements NotificationChannelInterface
{
    public function __construct(protected ?MailerDriverInterface $mailer = null)
    {
    }

    public function send(
        int|string $tenantId,
        mixed $recipient,
        string $title,
        string $message,
        array $options = []
    ): bool {
        $email = null;
        if (is_string($recipient) && filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            $email = $recipient;
        } elseif (is_object($recipient) && !empty($recipient->email)) {
            $email = (string) $recipient->email;
        } elseif (!empty($options['email'])) {
            $email = (string) $options['email'];
        }

        if ($email === null) {
            return false;
        }

        $mailer = $this->mailer;
        if ($mailer === null && function_exists('app')) {
            try {
                $mailer = app(MailerDriverInterface::class);
            } catch (\Throwable $e) {
                // Mailer belum terkonfigurasi
            }
        }

        if ($mailer === null) {
            return false;
        }

        $msg = (new Message())
            ->to($email)
            ->subject($title)
            ->text($message)
            ->html(nl2br(htmlspecialchars($message, ENT_QUOTES)));

        return $mailer->send($msg);
    }
}
