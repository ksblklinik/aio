<?php

declare(strict_types=1);

namespace Tests\Unit;

use Core\Container;
use Core\Console\Commands\SentinelSlowQueriesCommand;
use Core\Database\Connection;
use Core\Diagnostics\MemorySentinel;
use Core\Diagnostics\PerformanceSentinel;
use Core\Diagnostics\PerformanceSentinelInterface;
use Core\Diagnostics\QueryProfiler;
use Tests\TestCase;

class DiagnosticsPersistenceAndPiiRedactionTest extends TestCase
{
    private string $tempStorageFile;
    private QueryProfiler $profiler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempStorageFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aio_test_slow_' . uniqid() . '.json';
        $this->profiler = new QueryProfiler(
            slowThresholdMs: 10.0,
            maxBufferSize: 3,
            storagePath: $this->tempStorageFile
        );
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempStorageFile)) {
            @unlink($this->tempStorageFile);
        }
        parent::tearDown();
    }

    public function testPiiRedactionForNamedParameters(): void
    {
        $params = [
            'username' => 'budi_santoso',
            'password' => 'SuperSecret123!',
            'user_token' => 'abc-xyz-token',
            'api_key' => 'live_sk_1234567890',
            'nik' => '3201011205900001',
            'amount' => 150000.0,
        ];

        $redacted = $this->profiler->redactParams($params);

        $this->assertSame('budi_santoso', $redacted['username']);
        $this->assertSame('[REDACTED]', $redacted['password']);
        $this->assertSame('[REDACTED]', $redacted['user_token']);
        $this->assertSame('[REDACTED]', $redacted['api_key']);
        $this->assertSame('[REDACTED:NIK]', $redacted['nik']);
        $this->assertSame(150000.0, $redacted['amount']);
    }

    public function testPiiRedactionForPositionalParametersViaSqlContext(): void
    {
        // 1. INSERT statement
        $sqlInsert = "INSERT INTO users (name, password, email, nik_ktp) VALUES (?, ?, ?, ?)";
        $params = ['Ahmad', 'mypassword', 'ahmad@example.com', '3171012345670002'];

        $redacted = $this->profiler->redactParams($params, $sqlInsert);

        $this->assertSame('Ahmad', $redacted[0]);
        $this->assertSame('[REDACTED]', $redacted[1]); // password column
        $this->assertStringContainsString('@example.com', $redacted[2]); // email masked
        $this->assertSame('[REDACTED:NIK]', $redacted[3]); // nik_ktp column

        // 2. UPDATE statement
        $sqlUpdate = "UPDATE credentials SET pin = ?, secret_code = ? WHERE id = ?";
        $updateParams = ['1234', 'master-secret', 42];

        $redactedUpdate = $this->profiler->redactParams($updateParams, $sqlUpdate);
        $this->assertSame('[REDACTED]', $redactedUpdate[0]); // pin
        $this->assertSame('[REDACTED]', $redactedUpdate[1]); // secret_code
        $this->assertSame(42, $redactedUpdate[2]); // id
    }

    public function testPiiPatternRedactionForHashesTokensAndNik(): void
    {
        $params = [
            'hash_bcrypt' => '$2y$10$e8w.xY3143Q78w0X4q5P1.gU2h3J4k5L6m7N8o9P0',
            'jwt_token' => 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHpunv_w89gY',
            'nik_raw' => '3578010101900005',
            'email' => 'dokter@klinik.co.id',
            'bearer' => 'Bearer eyJhbGci...',
        ];

        $redacted = $this->profiler->redactParams($params);

        $this->assertSame('[REDACTED:HASH]', $redacted['hash_bcrypt']);
        $this->assertSame('[REDACTED:TOKEN]', $redacted['jwt_token']);
        $this->assertSame('[REDACTED:NIK]', $redacted['nik_raw']);
        $this->assertStringStartsWith('d***@', $redacted['email']);
        $this->assertSame('Bearer [REDACTED]', $redacted['bearer']);
    }

    public function testPersistentSlowQueryFileWrittenAndBoundedFifo(): void
    {
        $this->assertFileDoesNotExist($this->tempStorageFile);

        // Record 1st slow query (duration 15ms >= threshold 10ms)
        $this->profiler->record("SELECT * FROM patient WHERE id = ?", [1], 15.5);
        $this->assertFileExists($this->tempStorageFile);

        // Record 2nd slow query
        $this->profiler->record("SELECT * FROM billing WHERE invoice_id = ?", ['INV-001'], 22.0);

        // Record 3rd slow query
        $this->profiler->record("SELECT * FROM inventory WHERE stock < ?", [5], 30.0);

        // Verify count is 3
        $queries = $this->profiler->getSlowQueries(true);
        $this->assertCount(3, $queries);

        // Record 4th slow query - exceeds maxBufferSize (3), oldest should be trimmed
        $this->profiler->record("SELECT * FROM audit_logs WHERE id > ?", [100], 45.0);

        $queriesAfterTrim = $this->profiler->getSlowQueries(true);
        $this->assertCount(3, $queriesAfterTrim);
        $this->assertStringContainsString('billing', $queriesAfterTrim[0]['sql']);
        $this->assertStringContainsString('inventory', $queriesAfterTrim[1]['sql']);
        $this->assertStringContainsString('audit_logs', $queriesAfterTrim[2]['sql']);

        // Verify reset clears file
        $this->profiler->reset();
        $this->assertFileDoesNotExist($this->tempStorageFile);
        $this->assertEmpty($this->profiler->getSlowQueries(true));
    }

    public function testSentinelSlowQueriesCliCommandReadsPersistedBufferAndClears(): void
    {
        // Record slow query with sensitive param
        $this->profiler->record(
            "INSERT INTO auth_tokens (token, user_id) VALUES (?, ?)",
            ['eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpZCI6MX0.abcdef', 10],
            35.2
        );

        $container = Container::getInstance();
        $tempDb = new Connection('sqlite::memory:');
        $sentinel = new PerformanceSentinel($tempDb, $this->profiler, new MemorySentinel());

        $container->instance(PerformanceSentinelInterface::class, $sentinel);
        $command = new SentinelSlowQueriesCommand();
        $command->setContainer($container);

        $initialLevel = ob_get_level();
        try {
            ob_start();
            $exitCode = $command->handle();
            $output = ob_get_clean();

            $this->assertSame(0, $exitCode);
            $this->assertStringContainsString('SLOW QUERIES BUFFER', $output);
            $this->assertStringContainsString('Total Terdeteksi : 1 query lambat', $output);
            $this->assertStringContainsString('[REDACTED:TOKEN]', $output);

            // Test --clear option
            ob_start();
            $clearExitCode = $command->handle([], ['clear' => true]);
            $clearOutput = ob_get_clean();

            $this->assertSame(0, $clearExitCode);
            $this->assertStringContainsString('berhasil dikosongkan', $clearOutput);
            $this->assertEmpty($this->profiler->getSlowQueries(true));
        } finally {
            while (ob_get_level() > $initialLevel) {
                ob_end_clean();
            }
        }
    }
}
