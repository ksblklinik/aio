<?php

declare(strict_types=1);

namespace Core\Notification;

use Core\Database\TenantContext;
use Core\Notification\Channels\NotificationChannelInterface;

class ChannelManager
{
    /** @var array<string, NotificationChannelInterface> */
    protected array $channels = [];
    protected ?TenantContext $tenantContext;

    public function __construct(?TenantContext $tenantContext = null)
    {
        $this->tenantContext = $tenantContext;
    }

    public function registerChannel(string $name, NotificationChannelInterface $channel): self
    {
        $this->channels[$name] = $channel;
        return $this;
    }

    public function channel(string $name): NotificationChannelInterface
    {
        if (!isset($this->channels[$name])) {
            throw new \InvalidArgumentException("Notification channel [{$name}] belum terdaftar.");
        }
        return $this->channels[$name];
    }

    public function hasChannel(string $name): bool
    {
        return isset($this->channels[$name]);
    }

    /**
     * Kirim notifikasi ke penerima melalui satu atau lebih channel.
     *
     * @return array<string, bool> Status keberhasilan per channel (misal: ['database' => true, 'whatsapp' => true])
     */
    public function send(
        int|string $tenantId,
        mixed $recipient,
        string $title,
        string $message,
        array $channels = ['database'],
        array $options = []
    ): array {
        $results = [];

        foreach ($channels as $channelName) {
            if (!$this->hasChannel($channelName)) {
                $results[$channelName] = false;
                continue;
            }

            try {
                $results[$channelName] = $this->channel($channelName)->send(
                    $tenantId,
                    $recipient,
                    $title,
                    $message,
                    $options
                );
            } catch (\Throwable $e) {
                $results[$channelName] = false;
            }
        }

        return $results;
    }

    /**
     * Inisialisasi fluent builder untuk pengiriman notifikasi.
     */
    public function to(mixed $recipient, int|string|null $tenantId = null): PendingNotification
    {
        $resolvedTenantId = $tenantId ?? ($this->tenantContext ? $this->tenantContext->getTenantId() : 1) ?? 1;
        return new PendingNotification($this, $recipient, $resolvedTenantId);
    }
}
