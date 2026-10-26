<?php

declare(strict_types=1);

namespace Core\Database;

abstract class Seeder
{
    public function __construct(protected Connection $db)
    {
    }

    abstract public function run(): void;

    /**
     * Jalankan seeder turunan
     */
    public function call(string $seederClass): void
    {
        if (class_exists($seederClass) && is_subclass_of($seederClass, self::class)) {
            /** @var Seeder $instance */
            $instance = new $seederClass($this->db);
            $instance->run();
        }
    }
}
