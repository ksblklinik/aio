<?php

declare(strict_types=1);

namespace Tests\Unit;

use Core\Database\Connection;
use Core\Database\Migrator;
use Core\Database\TenantContext;
use Core\Sequence\SequenceManager;
use PHPUnit\Framework\TestCase;

class SequenceGeneratorTest extends TestCase
{
    private Connection $db;
    private Migrator $migrator;
    private SequenceManager $sequence;
    private TenantContext $tenantContext;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = new Connection('sqlite::memory:');
        $this->migrator = new Migrator($this->db);
        $this->migrator->runMigrationsFrom(__DIR__ . '/../../database/migrations');

        $this->tenantContext = TenantContext::getInstance();
        $this->tenantContext->clear();

        $this->sequence = new SequenceManager($this->db);

        // Bind ke app container
        $container = \Core\Container::getInstance();
        $container->instance(Connection::class, $this->db);
        $container->instance(SequenceManager::class, $this->sequence);
    }

    protected function tearDown(): void
    {
        $this->tenantContext->clear();
        parent::tearDown();
    }

    public function test_basic_sequential_numbering(): void
    {
        $tenantId = 1;

        $code1 = $this->sequence->next('invoice', 'INV', 5, 'never', null, $tenantId);
        $code2 = $this->sequence->next('invoice', 'INV', 5, 'never', null, $tenantId);
        $code3 = $this->sequence->next('invoice', 'INV', 5, 'never', null, $tenantId);

        $this->assertSame('INV-00001', $code1);
        $this->assertSame('INV-00002', $code2);
        $this->assertSame('INV-00003', $code3);

        $this->assertSame(3, $this->sequence->peek('invoice', $tenantId));
    }

    public function test_yearly_reset_format_pattern(): void
    {
        $tenantId = 1;
        $currentYear = date('Y');

        $code1 = $this->sequence->next('receipt', 'RCT', 4, 'yearly', null, $tenantId);
        $code2 = $this->sequence->next('receipt', 'RCT', 4, 'yearly', null, $tenantId);

        $this->assertSame("RCT/{$currentYear}/0001", $code1);
        $this->assertSame("RCT/{$currentYear}/0002", $code2);
    }

    public function test_custom_format_pattern(): void
    {
        $tenantId = 1;
        $year = date('Y');
        $month = date('m');

        $custom = $this->sequence->next(
            'donation',
            'DON',
            5,
            'never',
            '{PREFIX}/{YEAR}.{MONTH}/{NUMBER}',
            $tenantId
        );

        $this->assertSame("DON/{$year}.{$month}/00001", $custom);
    }

    public function test_tenant_isolation(): void
    {
        $codeTenant1 = $this->sequence->next('order', 'ORD', 4, 'never', null, 1);
        $codeTenant2 = $this->sequence->next('order', 'ORD', 4, 'never', null, 2);

        $this->assertSame('ORD-0001', $codeTenant1);
        $this->assertSame('ORD-0001', $codeTenant2);

        $nextTenant1 = $this->sequence->next('order', 'ORD', 4, 'never', null, 1);
        $this->assertSame('ORD-0002', $nextTenant1);
        $this->assertSame(1, $this->sequence->peek('order', 2));
    }

    public function test_reset_and_concurrency_anti_duplication(): void
    {
        $tenantId = 1;
        $generated = [];

        for ($i = 0; $i < 50; $i++) {
            $generated[] = $this->sequence->next('bulk_test', 'BLK', 5, 'never', null, $tenantId);
        }

        $this->assertCount(50, $generated);
        $this->assertCount(50, array_unique($generated), 'No duplicates must be generated');
        $this->assertSame('BLK-00001', $generated[0]);
        $this->assertSame('BLK-00050', $generated[49]);

        // Test manual reset
        $this->sequence->reset('bulk_test', 0, $tenantId);
        $this->assertSame(0, $this->sequence->peek('bulk_test', $tenantId));

        $afterReset = $this->sequence->next('bulk_test', 'BLK', 5, 'never', null, $tenantId);
        $this->assertSame('BLK-00001', $afterReset);
    }

    public function test_global_helper_with_tenant_context(): void
    {
        $this->tenantContext->setTenantId('42');

        $code = sequence('ticket', 'TCK', 3);
        $this->assertSame('TCK-001', $code);

        $code2 = sequence('ticket', 'TCK', 3);
        $this->assertSame('TCK-002', $code2);
    }
}
