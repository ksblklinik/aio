<?php

declare(strict_types=1);

namespace Core\Mail;

class Message
{
    /**
     * @param string|array<int, string> $to
     * @param array<int, string> $attachments
     */
    public function __construct(
        protected string|array $to = '',
        protected string $subject = '',
        protected string $html = '',
        protected string $text = '',
        protected ?string $from = null,
        protected ?string $replyTo = null,
        protected array $attachments = []
    ) {
    }

    public function to(string|array $to): self
    {
        $this->to = $to;
        return $this;
    }

    public function subject(string $subject): self
    {
        $this->subject = $subject;
        return $this;
    }

    public function html(string $html): self
    {
        $this->html = $html;
        if ($this->text === '') {
            $this->text = strip_tags($html);
        }
        return $this;
    }

    public function text(string $text): self
    {
        $this->text = $text;
        return $this;
    }

    public function from(string $from): self
    {
        $this->from = $from;
        return $this;
    }

    public function replyTo(string $replyTo): self
    {
        $this->replyTo = $replyTo;
        return $this;
    }

    public function attach(string $filePath): self
    {
        $this->attachments[] = $filePath;
        return $this;
    }

    /**
     * @return string|array<int, string>
     */
    public function getTo(): string|array
    {
        return $this->to;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getHtml(): string
    {
        return $this->html;
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function getFrom(): ?string
    {
        return $this->from;
    }

    public function getReplyTo(): ?string
    {
        return $this->replyTo;
    }

    /**
     * @return array<int, string>
     */
    public function getAttachments(): array
    {
        return $this->attachments;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'to' => $this->to,
            'subject' => $this->subject,
            'html' => $this->html,
            'text' => $this->text,
            'from' => $this->from,
            'reply_to' => $this->replyTo,
            'attachments' => $this->attachments,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            to: $data['to'] ?? '',
            subject: (string) ($data['subject'] ?? ''),
            html: (string) ($data['html'] ?? ''),
            text: (string) ($data['text'] ?? ''),
            from: isset($data['from']) ? (string) $data['from'] : null,
            replyTo: isset($data['reply_to']) ? (string) $data['reply_to'] : null,
            attachments: (array) ($data['attachments'] ?? [])
        );
    }
}
