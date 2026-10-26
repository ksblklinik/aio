<?php

declare(strict_types=1);

namespace Core\Mail;

use Core\Container;
use Core\Queue\JobInterface;
use RuntimeException;

class SendMailJob implements JobInterface
{
    /**
     * @param array<string, mixed> $data Data parameter pekerjaan
     * @param Container $container IoC Dependency Injection Container
     */
    public function handle(array $data, Container $container): void
    {
        if (empty($data['message'])) {
            throw new RuntimeException('SendMailJob requires message payload data.');
        }

        $message = Message::fromArray((array) $data['message']);

        /** @var \Core\Mail\Mailer $mailer */
        $mailer = $container->get(\Core\Mail\Mailer::class);
        $success = $mailer->send($message);

        if (!$success) {
            throw new RuntimeException("Failed to dispatch email to " . (is_array($message->getTo()) ? implode(', ', $message->getTo()) : $message->getTo()));
        }
    }
}
