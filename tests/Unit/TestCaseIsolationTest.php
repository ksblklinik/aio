<?php

declare(strict_types=1);

namespace Tests\Unit;

use Core\Container;
use Core\Database\TenantContext;
use Core\Config\Config;
use Tests\TestCase;

class TestCaseIsolationTest extends TestCase
{
    public function testStaticContextCanBeModified(): void
    {
        // Set tenant context
        TenantContext::getInstance()->setTenantId('tenant-777');
        $this->assertSame('tenant-777', TenantContext::getInstance()->getTenantId());

        // Bind mock in container
        $container = Container::getInstance();
        $container->instance('test_isolation_key', 'polluted_value');
        $this->assertSame('polluted_value', $container->get('test_isolation_key'));
    }

    public function testResetGlobalContextsRestoresCleanState(): void
    {
        // Simulate dirty state
        TenantContext::getInstance()->setTenantId('tenant-999');
        Container::getInstance()->instance('leak_check', 'dirty');

        // Run reset
        TestCase::resetGlobalContexts();

        // Verify TenantContext is fresh
        $this->assertNull(TenantContext::getInstance()->getTenantId());

        // Verify Container is fresh
        $freshContainer = Container::getInstance();
        $this->assertFalse($freshContainer->has('leak_check'));
    }
}
