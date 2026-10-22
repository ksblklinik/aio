<?php

declare(strict_types=1);

namespace Core\Events;

interface ListenerInterface
{
    /**
     * Menangani pemrosesan event.
     */
    public function handle(object $event): void;

    /**
     * Menentukan apakah listener ini dieksekusi secara asinkron via Database Queue.
     */
    public function shouldQueue(): bool;
}
