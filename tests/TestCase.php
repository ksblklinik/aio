<?php

declare(strict_types=1);

namespace Tests;

use Core\Container;
use Core\Database\TenantContext;
use Core\Config\Config;
use Core\Log\Logger;
use Core\Inertia\Inertia;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function tearDown(): void
    {
        try {
            $this->resetGlobalContexts();
        } finally {
            parent::tearDown();
        }
    }

    /**
     * Reset all global singleton state to prevent cross-test contamination.
     */
    public static function resetGlobalContexts(): void
    {
        // 1. Reset TenantContext
        try {
            TenantContext::getInstance()->setTenantId(null);
            TenantContext::setInstance(null);
        } catch (\Throwable) {
        }

        // 2. Reset Container
        try {
            Container::setInstance(null);
        } catch (\Throwable) {
        }

        // 3. Reset Config
        try {
            Config::setInstance(null);
        } catch (\Throwable) {
        }

        // 4. Reset Logger & Inertia
        try {
            Logger::setInstance(null);
        } catch (\Throwable) {
        }
        try {
            Inertia::setInstance(null);
        } catch (\Throwable) {
        }
    }
}
