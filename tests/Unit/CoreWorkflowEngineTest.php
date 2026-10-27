<?php

declare(strict_types=1);

namespace Tests\Unit;

use Core\Auth\Gate;
use Core\Database\Connection;
use Core\Database\TenantContext;
use Core\Events\EventDispatcher;
use Core\Workflow\WorkflowDefinition;
use Core\Workflow\WorkflowException;
use Core\Workflow\WorkflowManager;
use PHPUnit\Framework\TestCase;

class CoreWorkflowEngineTest extends TestCase
{
    private Connection $db;
    private TenantContext $tenantContext;
    private WorkflowManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = new Connection('sqlite::memory:');
        $this->db->getPdo()->exec("
            CREATE TABLE IF NOT EXISTS workflow_transitions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                entity_type VARCHAR(64) NOT NULL,
                entity_id VARCHAR(64) NOT NULL,
                from_status VARCHAR(64) NOT NULL,
                to_status VARCHAR(64) NOT NULL,
                transition_name VARCHAR(64) NULL,
                actor_id INTEGER NULL,
                actor_name VARCHAR(255) NULL,
                notes TEXT NULL,
                metadata TEXT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
        ");

        $this->tenantContext = TenantContext::getInstance();
        $this->tenantContext->setTenantId('1', ['name' => 'Yayasan Peduli']);

        $this->manager = new WorkflowManager($this->db, $this->tenantContext, null);
    }

    public function test_workflow_definition_fluent_and_array_configuration(): void
    {
        // 1. Fluent builder
        $def = WorkflowDefinition::make('surat_sk')
            ->states(['draft', 'review', 'published', 'archived'])
            ->initial('draft')
            ->addTransition('submit_review', ['draft'], 'review')
            ->addTransition('approve_publish', ['review'], 'published')
            ->addTransition('archive', ['published'], 'archived');

        $this->assertSame('surat_sk', $def->getName());
        $this->assertSame('draft', $def->getInitialState());
        $this->assertTrue($def->hasState('review'));
        $this->assertFalse($def->hasState('deleted'));

        $this->assertNotNull($def->findTransition('draft', 'submit_review'));
        $this->assertNotNull($def->findTransition('draft', 'review'));
        $this->assertNull($def->findTransition('draft', 'published'));

        // 2. Array configuration
        $orderDef = $this->manager->define('order', [
            'states' => ['pending', 'paid', 'shipped', 'cancelled'],
            'initial' => 'pending',
            'transitions' => [
                'pay' => ['from' => ['pending'], 'to' => 'paid'],
                'ship' => ['from' => ['paid'], 'to' => 'shipped'],
                'cancel' => ['from' => ['*'], 'to' => 'cancelled'],
            ],
        ]);

        $this->assertInstanceOf(WorkflowDefinition::class, $orderDef);
        $this->assertSame('order', $orderDef->getName());
        $this->assertNotNull($orderDef->findTransition('pending', 'pay'));
        $this->assertNotNull($orderDef->findTransition('paid', 'shipped'));
        // Wildcard transition
        $this->assertNotNull($orderDef->findTransition('paid', 'cancel'));
        $this->assertNotNull($orderDef->findTransition('pending', 'cancel'));
    }

    public function test_can_transition_evaluates_legal_and_illegal_transitions(): void
    {
        $this->manager->define('notulen', [
            'states' => ['draft', 'verifikasi_sekretaris', 'pengesahan_ketua', 'arsip'],
            'initial' => 'draft',
            'transitions' => [
                'ajukan' => ['from' => ['draft'], 'to' => 'verifikasi_sekretaris'],
                'sahkan' => ['from' => ['verifikasi_sekretaris'], 'to' => 'pengesahan_ketua'],
                'arsipkan' => ['from' => ['pengesahan_ketua'], 'to' => 'arsip'],
            ],
        ]);

        // Legal transition
        $this->assertTrue($this->manager->can('notulen', 'draft', 'verifikasi_sekretaris'));
        $this->assertTrue($this->manager->can('notulen', 'draft', 'ajukan'));

        // Illegal transitions
        $this->assertFalse($this->manager->can('notulen', 'draft', 'pengesahan_ketua'));
        $this->assertFalse($this->manager->can('notulen', 'draft', 'arsip'));
        $this->assertFalse($this->manager->can('non_existent_entity', 'draft', 'arsip'));
    }

    public function test_guards_closure_and_permission_enforcement(): void
    {
        $allowManager = false;

        $this->manager->define('reimbursement', [
            'states' => ['draft', 'approved', 'rejected'],
            'initial' => 'draft',
            'transitions' => [
                'approve' => [
                    'from' => ['draft'],
                    'to' => 'approved',
                    'guards' => [
                        function ($actor, $entity) use (&$allowManager) {
                            return $allowManager === true;
                        },
                    ],
                ],
            ],
        ]);

        // Guard returns false
        $this->assertFalse($this->manager->can('reimbursement', 'draft', 'approve'));

        $this->expectException(WorkflowException::class);
        $this->expectExceptionCode(403);
        $this->manager->transition('reimbursement', 101, 'draft', 'approve');
    }

    public function test_successful_transition_records_audit_trail_and_dispatches_event(): void
    {
        $this->manager->define('surat_keputusan', [
            'states' => ['draft', 'reviewed', 'published'],
            'initial' => 'draft',
            'transitions' => [
                'review' => ['from' => ['draft'], 'to' => 'reviewed'],
                'publish' => ['from' => ['reviewed'], 'to' => 'published'],
            ],
        ]);

        $actor = (object) ['id' => 42, 'name' => 'Budi Sekretaris', 'email' => 'budi@yayasan.org'];

        $result = $this->manager->transition(
            'surat_keputusan',
            'SK-2026-001',
            'draft',
            'review',
            $actor,
            'Catatan: Kuorum rapat pembina terpenuhi',
            null,
            ['kuorum' => 5, 'kehadiran' => '100%']
        );

        $this->assertSame('draft', $result['from']);
        $this->assertSame('reviewed', $result['to']);
        $this->assertSame('review', $result['transition']);
        $this->assertSame(42, $result['actor_id']);
        $this->assertSame('Budi Sekretaris', $result['actor_name']);

        // Check history retrieval
        $history = $this->manager->getHistory('surat_keputusan', 'SK-2026-001');
        $this->assertCount(1, $history);
        $this->assertSame('draft', $history[0]['from_status']);
        $this->assertSame('reviewed', $history[0]['to_status']);
        $this->assertSame('Catatan: Kuorum rapat pembina terpenuhi', $history[0]['notes']);
        $this->assertIsArray($history[0]['metadata']);
        $this->assertSame(5, $history[0]['metadata']['kuorum']);

        // Second transition
        $this->manager->transition(
            'surat_keputusan',
            'SK-2026-001',
            'reviewed',
            'publish',
            (object) ['id' => 1, 'name' => 'Ketua Pembina'],
            'Disahkan secara resmi'
        );

        $updatedHistory = $this->manager->getHistory('surat_keputusan', 'SK-2026-001');
        $this->assertCount(2, $updatedHistory);
        $this->assertSame('reviewed', $updatedHistory[1]['from_status']);
        $this->assertSame('published', $updatedHistory[1]['to_status']);
    }

    public function test_workflow_history_tenant_isolation(): void
    {
        $this->manager->define('dokumen', [
            'states' => ['draft', 'final'],
            'transitions' => [
                'finalize' => ['from' => ['draft'], 'to' => 'final'],
            ],
        ]);

        // Tenant 1 records transition
        $this->manager->transition('dokumen', 'DOC-99', 'draft', 'finalize', 1, 'Catatan T1', null, [], 1);

        // Tenant 2 records transition on same entity ID
        $this->manager->transition('dokumen', 'DOC-99', 'draft', 'finalize', 2, 'Catatan T2', null, [], 2);

        // Verify history per tenant
        $historyT1 = $this->manager->getHistory('dokumen', 'DOC-99', 1);
        $this->assertCount(1, $historyT1);
        $this->assertSame('Catatan T1', $historyT1[0]['notes']);

        $historyT2 = $this->manager->getHistory('dokumen', 'DOC-99', 2);
        $this->assertCount(1, $historyT2);
        $this->assertSame('Catatan T2', $historyT2[0]['notes']);
    }

    public function test_illegal_transition_throws_exception_with_422(): void
    {
        $this->manager->define('proposal', [
            'states' => ['draft', 'submitted', 'funded'],
            'transitions' => [
                'submit' => ['from' => ['draft'], 'to' => 'submitted'],
            ],
        ]);

        $this->expectException(WorkflowException::class);
        $this->expectExceptionCode(422);
        $this->manager->transition('proposal', 'PROP-01', 'draft', 'funded');
    }

    public function test_workflow_gate_permission_guard(): void
    {
        $gate = $this->createMock(Gate::class);
        $gate->method('allows')
            ->willReturnCallback(function ($user, $permission, $tenantId) {
                $userId = is_int($user) ? $user : (isset($user->id) ? (int) $user->id : 0);
                return $permission === 'surat.approve' && $userId === 1;
            });

        $managerWithGate = new WorkflowManager($this->db, $this->tenantContext, $gate);
        $managerWithGate->define('surat_tugas', [
            'states' => ['draft', 'approved'],
            'transitions' => [
                'approve' => [
                    'from' => ['draft'],
                    'to' => 'approved',
                    'guards' => ['can:surat.approve'],
                ],
            ],
        ]);

        $nonApprover = (object) ['id' => 5, 'name' => 'Staf Biasa'];
        $approver = (object) ['id' => 1, 'name' => 'Ketua Pengurus'];

        $this->assertFalse($managerWithGate->can('surat_tugas', 'draft', 'approve', $nonApprover));
        $this->assertTrue($managerWithGate->can('surat_tugas', 'draft', 'approve', $approver));

        $res = $managerWithGate->transition('surat_tugas', 'ST-001', 'draft', 'approve', $approver);
        $this->assertSame('approved', $res['to']);
    }

    public function test_workflow_helper_resolves_manager_and_definitions(): void
    {
        $app = new \Core\Foundation\Application(dirname(__DIR__, 2));
        $app->getContainer()->singleton(Connection::class, fn() => $this->db);

        $this->assertInstanceOf(WorkflowManager::class, workflow());

        workflow()->define('mou_kemitraan', [
            'states' => ['draft', 'signed'],
            'transitions' => [
                'sign' => ['from' => ['draft'], 'to' => 'signed'],
            ],
        ]);

        $def = workflow('mou_kemitraan');
        $this->assertInstanceOf(WorkflowDefinition::class, $def);
        $this->assertSame('mou_kemitraan', $def->getName());
    }
}
