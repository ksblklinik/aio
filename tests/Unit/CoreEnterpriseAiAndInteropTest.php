<?php

declare(strict_types=1);

namespace Tests\Unit;

use Core\Ai\AiManager;
use Core\Ai\MockAiDriver;
use Core\Database\Connection;
use Core\Database\DatabaseManager;
use Core\Database\TenantContext;
use Core\Database\TenantScope;
use Core\Foundation\Application;
use Core\Graph\GraphHelper;
use Core\Http\CsvStream;
use Core\Http\Response;
use Core\Vector\SqliteVectorDriver;
use Core\Vector\VectorManager;
use PHPUnit\Framework\TestCase;

class CoreEnterpriseAiAndInteropTest extends TestCase
{
    private Application $app;
    private Connection $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = new Application(dirname(__DIR__, 2));
        $this->db = new Connection('sqlite::memory:');

        TenantContext::getInstance()->setTenantId('1', ['name' => 'Acme Enterprise']);

        // Inisialisasi tabel test
        $this->db->statement("
            CREATE TABLE IF NOT EXISTS test_products (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                name TEXT NOT NULL,
                sku TEXT NOT NULL,
                deleted_at TIMESTAMP NULL
            );
        ");

        $this->db->statement("
            CREATE TABLE IF NOT EXISTS vector_embeddings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                collection TEXT NOT NULL,
                item_id TEXT NOT NULL,
                embedding BLOB NOT NULL,
                dimensions INTEGER NOT NULL,
                metadata TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(tenant_id, collection, item_id)
            );
        ");
    }

    public function test_database_manager_multi_connection(): void
    {
        $manager = new DatabaseManager('sqlite', [
            'sqlite' => ['driver' => 'sqlite', 'database' => ':memory:'],
            'analytics' => ['driver' => 'sqlite', 'database' => ':memory:'],
        ]);

        $defaultConn = $manager->connection();
        $this->assertInstanceOf(Connection::class, $defaultConn);

        $analyticsConn = $manager->connection('analytics');
        $this->assertInstanceOf(Connection::class, $analyticsConn);

        // Global helper db() with connection
        $scope = db('test_products');
        $this->assertInstanceOf(TenantScope::class, $scope);
    }

    public function test_tenant_scope_search_and_chunk(): void
    {
        $scope = new TenantScope(TenantContext::getInstance(), $this->db);

        // Insert dummy data
        $scope->insert('test_products', ['name' => 'MacBook Pro M3', 'sku' => 'APL-MBP-01']);
        $scope->insert('test_products', ['name' => 'Dell XPS 15', 'sku' => 'DLL-XPS-02']);
        $scope->insert('test_products', ['name' => 'MacBook Air M2', 'sku' => 'APL-MBA-03']);

        // Search multi-column
        $results = $scope->search('test_products', ['name', 'sku'], 'MacBook');
        $this->assertCount(2, $results);

        $resultsSku = $scope->search('test_products', ['name', 'sku'], 'DLL');
        $this->assertCount(1, $resultsSku);
        $this->assertSame('Dell XPS 15', $resultsSku[0]['name']);

        // Chunking test
        $totalProcessed = 0;
        $scope->chunk('test_products', 2, function ($rows, $page) use (&$totalProcessed) {
            $totalProcessed += count($rows);
        });

        $this->assertSame(3, $totalProcessed);
    }

    public function test_sse_streaming_response(): void
    {
        $response = Response::sse(function ($send) {
            $send(['event_type' => 'worker_progress', 'percent' => 50], 'progress', 'msg-1');
            $send("Task completed successfully.", null, null);
        });

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/event-stream; charset=UTF-8', $response->getHeader('Content-Type'));
        $this->assertSame('no-cache, no-transform', $response->getHeader('Cache-Control'));

        $this->assertNotNull($response->getStreamCallback());

        // Capture buffer execution
        ob_start();
        $callback = $response->getStreamCallback();
        $callback();
        $output = ob_get_clean();

        $this->assertStringContainsString("id: msg-1\n", $output);
        $this->assertStringContainsString("event: progress\n", $output);
        $this->assertStringContainsString('data: {"event_type":"worker_progress","percent":50}', $output);
        $this->assertStringContainsString('data: Task completed successfully.', $output);
    }

    public function test_csv_stream_download_and_parse(): void
    {
        $headers = ['ID', 'Nama', 'Harga'];
        $rows = [
            ['1', 'Produk A', '10000'],
            ['2', 'Produk B', '25000'],
        ];

        $response = csv_download('produk.csv', $headers, $rows);
        $this->assertSame('text/csv; charset=UTF-8', $response->getHeader('Content-Type'));
        $this->assertStringContainsString('attachment; filename="produk.csv"', $response->getHeader('Content-Disposition'));

        // Test CsvStream::parse
        $csvContent = "ID,Nama,Harga\n1,Produk A,10000\n2,Produk B,25000\n";
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $csvContent);
        rewind($stream);

        $parsed = iterator_to_array(csv_parse($stream));
        fclose($stream);

        $this->assertCount(2, $parsed);
        $this->assertSame('Produk A', $parsed[0]['Nama']);
        $this->assertSame('25000', $parsed[1]['Harga']);
    }

    public function test_sqlite_vector_store_cosine_similarity(): void
    {
        $driver = new SqliteVectorDriver($this->db, TenantContext::getInstance());
        $manager = new VectorManager($driver);

        // Vector 4-dimensi
        $v1 = [1.0, 0.0, 0.0, 0.0]; // Kategori A
        $v2 = [0.9, 0.1, 0.0, 0.0]; // Mirip Kategori A
        $v3 = [0.0, 0.0, 1.0, 0.0]; // Kategori B (orthogonal)

        $manager->upsert('kb', 'doc-1', $v1, ['title' => 'Doc 1 A']);
        $manager->upsert('kb', 'doc-2', $v2, ['title' => 'Doc 2 A-Similar']);
        $manager->upsert('kb', 'doc-3', $v3, ['title' => 'Doc 3 B']);

        // Query vector [1.0, 0.0, 0.0, 0.0]
        $results = $manager->search('kb', [1.0, 0.0, 0.0, 0.0], limit: 2);

        $this->assertCount(2, $results);
        $this->assertSame('doc-1', $results[0]['item_id']);
        $this->assertEqualsWithDelta(1.0, $results[0]['score'], 0.001);

        $this->assertSame('doc-2', $results[1]['item_id']);
        $this->assertGreaterThan(0.9, $results[1]['score']);

        // Helper global vector_store()
        $this->assertInstanceOf(SqliteVectorDriver::class, vector_store());
    }

    public function test_graph_helper_hierarchy_and_cycles(): void
    {
        $nodes = [
            ['id' => '1', 'parent_id' => null, 'name' => 'Direktur Utama'],
            ['id' => '2', 'parent_id' => '1', 'name' => 'Manajer IT'],
            ['id' => '3', 'parent_id' => '1', 'name' => 'Manajer Keuangan'],
            ['id' => '4', 'parent_id' => '2', 'name' => 'Senior Developer'],
            ['id' => '5', 'parent_id' => '4', 'name' => 'Junior Developer'],
        ];

        // Descendants
        $descendants = GraphHelper::getDescendants($nodes, '2');
        $this->assertCount(2, $descendants);
        $this->assertSame('Senior Developer', $descendants[0]['name']);
        $this->assertSame('Junior Developer', $descendants[1]['name']);

        // Ancestors
        $ancestors = GraphHelper::getAncestors($nodes, '5');
        $this->assertCount(3, $ancestors);
        $this->assertSame('Senior Developer', $ancestors[0]['name']);
        $this->assertSame('Manajer IT', $ancestors[1]['name']);
        $this->assertSame('Direktur Utama', $ancestors[2]['name']);

        // Build Nested Tree
        $tree = GraphHelper::buildTree($nodes);
        $this->assertCount(1, $tree);
        $this->assertCount(2, $tree[0]['children']); // IT dan Keuangan

        // Cycle Detection (Valid graph = null)
        $this->assertNull(GraphHelper::detectCycle($nodes));

        // Create cycle: 5 -> 2
        $cyclicNodes = [
            ['id' => '1', 'parent_id' => '3'],
            ['id' => '2', 'parent_id' => '1'],
            ['id' => '3', 'parent_id' => '2'],
        ];
        $cycle = GraphHelper::detectCycle($cyclicNodes);
        $this->assertNotNull($cycle);
        $this->assertContains('1', $cycle);
    }

    public function test_ai_manager_and_mock_driver(): void
    {
        $mock = new MockAiDriver();
        $manager = new AiManager($mock, 'mock');

        // Chat
        $reply = $manager->chat([
            ['role' => 'user', 'content' => 'Halo sistem'],
        ]);
        $this->assertStringContainsString('Halo!', $reply);

        // Streaming tokens
        $chunks = [];
        $manager->stream([
            ['role' => 'user', 'content' => 'Halo sistem'],
        ], function ($chunk) use (&$chunks) {
            $chunks[] = $chunk;
        });
        $this->assertNotEmpty($chunks);

        // Embeddings
        $vector = $manager->embed("Sistem Invoice Pro");
        $this->assertCount(8, $vector);
        $this->assertIsFloat($vector[0]);

        // Structured JSON Extraction
        $extracted = $manager->extractJson("Ekstrak data faktur", [
            'total' => 'int',
            'is_paid' => 'bool',
        ]);
        $this->assertSame(100, $extracted['total']);
        $this->assertTrue($extracted['is_paid']);

        // Global helper ai()
        $this->assertInstanceOf(AiManager::class, ai());
    }
}
