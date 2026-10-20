<?php

declare(strict_types=1);

if (!function_exists('app')) {
    /**
     * Helper global untuk mengakses container atau instansiasi kelas
     */
    function app(?string $abstract = null): mixed
    {
        $container = \Core\Container::getInstance();
        if ($abstract === null) {
            return $container;
        }
        return $container->get($abstract);
    }
}
