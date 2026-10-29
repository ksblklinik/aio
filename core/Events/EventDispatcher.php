<?php

declare(strict_types=1);

namespace Core\Events;

use Core\Container;
use Core\Queue\QueueManager;
use Core\Webhook\WebhookDispatcher;
use Throwable;

class EventDispatcher
{
    /** @var array<string, array<int, callable|string|ListenerInterface>> */
    private array $listeners = [];

    public function __construct(
        private ?Container $container = null,
        private ?QueueManager $queue = null,
        private ?WebhookDispatcher $webhookDispatcher = null
    ) {
    }

    /**
     * Daftarkan listener untuk class event tertentu atau event name string.
     */
    public function listen(string $eventClassOrName, callable|string|ListenerInterface $listener): void
    {
        $this->listeners[$eventClassOrName][] = $listener;
    }

    /**
     * Pancarkan event ke seluruh listener sinkron, asinkron, dan auto-bridge ke webhook.
     */
    public function dispatch(object $event): void
    {
        $eventName = ($event instanceof Event) ? $event->getEventName() : null;
        $eventClass = get_class($event);

        // 1. Eksekusi Listeners yang cocok
        $matchedListeners = array_merge(
            $this->listeners[$eventClass] ?? [],
            $eventName ? ($this->listeners[$eventName] ?? []) : [],
            $this->listeners['*'] ?? []
        );

        foreach ($matchedListeners as $listener) {
            $this->executeListener($listener, $event);
        }

        // 2. Auto-Bridge ke Outbound Webhooks jika event terkait tenant
        if ($this->webhookDispatcher !== null && $event instanceof Event && $event->getTenantId() !== null) {
            try {
                $this->webhookDispatcher->dispatch(
                    $event->getTenantId(),
                    $event->getEventName(),
                    $event->toPayload()
                );
            } catch (Throwable $e) {
                \Core\Log\Logger::getInstance()->warning("Webhook auto-bridge dispatch failed: " . $e->getMessage());
            }
        }
    }

    /**
     * Eksekusi listener (sinkron atau delegasikan ke antrean jika shouldQueue)
     */
    private function executeListener(callable|string|ListenerInterface $listener, object $event): void
    {
        if (is_string($listener)) {
            $instance = $this->container ? $this->container->get($listener) : new $listener();
            $listener = $instance;
        }

        if ($listener instanceof ListenerInterface) {
            if ($listener->shouldQueue() && $this->queue !== null) {
                $tenantId = ($event instanceof Event) ? $event->getTenantId() : null;
                $this->queue->push(
                    jobClass: QueuedEventJob::class,
                    data: [
                        'listener' => get_class($listener),
                        'event_serialized' => serialize($event),
                    ],
                    tenantId: $tenantId,
                    queue: 'events'
                );
                return;
            }

            $listener->handle($event);
            return;
        }

        if (is_callable($listener)) {
            $listener($event);
        }
    }

    /**
     * Ambil seluruh listener terdaftar (untuk inspeksi/pengujian)
     *
     * @return array<string, array<int, callable|string|ListenerInterface>>
     */
    public function getListeners(): array
    {
        return $this->listeners;
    }
}
