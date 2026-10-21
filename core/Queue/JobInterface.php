<?php

declare(strict_types=1);

namespace Core\Queue;

use Core\Container;

interface JobInterface
{
    /**
     * Eksekusi pekerjaan di latar belakang.
     *
     * @param array<string, mixed> $data Data parameter pekerjaan
     * @param Container $container IoC Dependency Injection Container
     */
    public function handle(array $data, Container $container): void;
}
