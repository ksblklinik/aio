<?php

declare(strict_types=1);

namespace Core\Mail;

class LogMailerDriver implements MailerDriverInterface
{
    public function __construct(
        protected string $logPath
    ) {
        $dir = dirname($this->logPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    public function send(Message $message): bool
    {
        $timestamp = date('Y-m-d H:i:s');
        $to = is_array($message->getTo()) ? implode(', ', $message->getTo()) : $message->getTo();
        $subject = $message->getSubject();
        $from = $message->getFrom() ?? 'noreply@aio.local';
        $body = $message->getText() ?: strip_tags($message->getHtml());
        $attachmentCount = count($message->getAttachments());

        $divider = str_repeat('-', 70);
        $entry = <<<LOG
[{$timestamp}] MAIL SENT
From: {$from}
To: {$to}
Subject: {$subject}
Attachments: count = {$attachmentCount}
{$divider}
{$body}
{$divider}


LOG;

        return file_put_contents($this->logPath, $entry, FILE_APPEND | LOCK_EX) !== false;
    }
}
