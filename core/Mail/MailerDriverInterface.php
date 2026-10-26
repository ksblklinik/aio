<?php

declare(strict_types=1);

namespace Core\Mail;

interface MailerDriverInterface
{
    /**
     * Send an email message.
     */
    public function send(Message $message): bool;
}
