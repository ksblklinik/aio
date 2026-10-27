<?php

declare(strict_types=1);

namespace Core\Notification;

class PendingNotification
{
    protected ChannelManager $manager;
    protected mixed $recipient;
    protected int|string $tenantId;
    protected array $channels = ['database'];
    protected array $options = [];

    public function __construct(ChannelManager $manager, mixed $recipient, int|string $tenantId)
    {
        $this->manager = $manager;
        $this->recipient = $recipient;
        $this->tenantId = $tenantId;
    }

    public function via(array|string $channels): self
    {
        $this->channels = is_array($channels) ? $channels : [$channels];
        return $this;
    }

    public function type(string $type): self
    {
        $this->options['type'] = $type;
        return $this;
    }

    public function actionUrl(string $url): self
    {
        $this->options['action_url'] = $url;
        return $this;
    }

    public function icon(string $icon): self
    {
        $this->options['icon'] = $icon;
        return $this;
    }

    public function with(array $options): self
    {
        $this->options = array_merge($this->options, $options);
        return $this;
    }

    /**
     * Kirim notifikasi dengan judul dan isi pesan.
     *
     * @return array<string, bool>
     */
    public function send(string $title, string $message): array
    {
        return $this->manager->send(
            $this->tenantId,
            $this->recipient,
            $title,
            $message,
            $this->channels,
            $this->options
        );
    }
}
