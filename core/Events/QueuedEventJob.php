<?php

declare(strict_types=1);

namespace Core\Events;

use Core\Container;
use Core\Queue\JobInterface;
use RuntimeException;

class QueuedEventJob implements JobInterface
{
    /**
     * Eksekusi pekerjaan event di latar belakang.
     *
     * @param array<string, mixed> $data
     */
    public function handle(array $data, Container $container): void
    {
        $listenerClass = $data['listener'] ?? null;
        $eventSerialized = $data['event_serialized'] ?? null;

        if (!$listenerClass || !$eventSerialized) {
            throw new RuntimeException('Invalid queued event payload: listener or event data is missing');
        }

        $event = unserialize($eventSerialized);
        $listener = $container->get($listenerClass);

        if ($listener instanceof ListenerInterface) {
            $listener->handle($event);
        } elseif (is_callable($listener)) {
            $listener($event);
        }
    }
}
