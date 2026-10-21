<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class BootstrapTest extends TestCase
{
    public function test_framework_environment_is_ready(): void
    {
        $this->assertTrue(true);
        $this->assertTrue(function_exists('app'));
    }
}
