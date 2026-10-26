<?php

declare(strict_types=1);

namespace Tests\Unit;

use Core\Foundation\Application;
use Core\Http\Request;
use Core\Http\UploadedFile;
use Core\Log\Logger;
use Exception;
use PHPUnit\Framework\TestCase;

class LoggerAndHttpCompletenessTest extends TestCase
{
    private string $tempLogDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempLogDir = sys_get_temp_dir() . '/aio_logs_' . uniqid();
        mkdir($this->tempLogDir, 0777, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempLogDir)) {
            foreach (glob($this->tempLogDir . '/*') ?: [] as $f) {
                unlink($f);
            }
            rmdir($this->tempLogDir);
        }
        parent::tearDown();
    }

    public function test_logger_writes_daily_log_file_with_context(): void
    {
        $logger = new Logger($this->tempLogDir);
        $logger->info("User logged in successfully", ['user_id' => 42, 'ip' => '127.0.0.1']);
        $logger->error("Payment gateway timeout", ['gateway' => 'midtrans', 'code' => 504]);

        $logFile = $this->tempLogDir . '/aio-' . date('Y-m-d') . '.log';
        $this->assertFileExists($logFile);

        $content = file_get_contents($logFile);
        $this->assertStringContainsString('[INFO]: User logged in successfully {"user_id":42,"ip":"127.0.0.1"}', $content);
        $this->assertStringContainsString('[ERROR]: Payment gateway timeout {"gateway":"midtrans","code":504}', $content);
    }

    public function test_uploaded_file_capabilities(): void
    {
        $tempSource = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($tempSource, "Sample document content");

        $file = new UploadedFile(
            name: 'sample.pdf',
            type: 'application/pdf',
            tmpName: $tempSource,
            error: UPLOAD_ERR_OK,
            size: filesize($tempSource)
        );

        $this->assertTrue($file->isValid());
        $this->assertFalse($file->hasError());
        $this->assertSame('sample.pdf', $file->getClientOriginalName());
        $this->assertSame('pdf', $file->getClientOriginalExtension());
        $this->assertSame('application/pdf', $file->getClientMimeType());
        $this->assertSame(filesize($tempSource), $file->getSize());
        $this->assertSame("Sample document content", $file->getContent());

        $tempDest = sys_get_temp_dir() . '/moved_' . uniqid() . '.pdf';
        $moved = $file->moveTo($tempDest);

        $this->assertTrue($moved);
        $this->assertFileExists($tempDest);
        $this->assertSame("Sample document content", file_get_contents($tempDest));

        unlink($tempDest);
    }

    public function test_request_captures_files_and_supports_method_spoofing(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'req');
        file_put_contents($tempFile, "Image binary data");

        $request = new Request(
            method: 'POST',
            uri: '/projects/42',
            headers: [],
            queryParams: [],
            bodyParams: ['_method' => 'PUT', 'name' => 'Renamed Project'],
            serverParams: ['REQUEST_METHOD' => 'POST'],
            cookieParams: [],
            files: [
                'avatar' => new UploadedFile('avatar.png', 'image/png', $tempFile, UPLOAD_ERR_OK, 100)
            ]
        );

        // Method spoofing
        $this->assertSame('PUT', $request->method());
        $this->assertTrue($request->isMethod('PUT'));
        $this->assertFalse($request->isMethod('POST'));

        // Uploaded files access
        $this->assertTrue($request->hasFile('avatar'));
        $this->assertFalse($request->hasFile('nonexistent'));
        $this->assertInstanceOf(UploadedFile::class, $request->file('avatar'));
        $this->assertSame('avatar.png', $request->file('avatar')->getClientOriginalName());

        unlink($tempFile);
    }

    public function test_http_exception_handling_hides_trace_when_debug_disabled(): void
    {
        $app = new Application(dirname(__DIR__, 2));
        config()->set('app.debug', false);

        $request = new Request('GET', '/test/simulate-500-error', headers: ['x-inertia' => 'true']);
        $router = $app->getContainer()->get(\Core\Router::class);
        $router->get('/test/simulate-500-error', function () {
            throw new Exception("Sensitive internal database connection failed: secret_db_pw");
        });

        $response = $app->handleHttp($request);
        $this->assertSame(500, $response->getStatusCode());

        $json = json_decode($response->getBody(), true);
        $this->assertSame('Server Error', $json['error']);
        // Message must NOT leak the secret message or stack trace
        $this->assertSame('Terjadi kesalahan pada server.', $json['message']);
        $this->assertArrayNotHasKey('trace', $json);
        $this->assertArrayNotHasKey('file', $json);
    }
}
