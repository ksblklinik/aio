<?php

declare(strict_types=1);

namespace Tests\Unit;

use Core\Http\Request;
use Core\Inertia\Inertia;
use PHPUnit\Framework\TestCase;

class InertiaTest extends TestCase
{
    private Inertia $inertia;

    protected function setUp(): void
    {
        $this->inertia = new Inertia();
        $this->inertia->setVersion('1.0.0');
    }

    public function test_renders_json_when_inertia_header_present(): void
    {
        $request = new Request('GET', '/dashboard', headers: ['x-inertia' => 'true']);
        $this->inertia->setRequest($request);

        $response = $this->inertia->render('Dashboard', ['title' => 'Overview']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('true', $response->getHeader('X-Inertia'));
        $this->assertSame('application/json', $response->getHeader('Content-Type'));

        $data = json_decode($response->getBody(), true);
        $this->assertSame('Dashboard', $data['component']);
        $this->assertSame('Overview', $data['props']['title']);
        $this->assertSame('/dashboard', $data['url']);
        $this->assertSame('1.0.0', $data['version']);
    }

    public function test_renders_html_with_data_page_on_initial_visit(): void
    {
        $request = new Request('GET', '/');
        $this->inertia->setRequest($request);

        // Dummy view wrapper template
        $template = __DIR__ . '/test_app.php';
        file_put_contents($template, '<html><body><div id="app" data-page="<?= htmlspecialchars(json_encode($page)) ?>"></div></body></html>');
        $this->inertia->setRootView($template);

        $response = $this->inertia->render('Home', ['user' => 'John']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('text/html', $response->getHeader('Content-Type'));
        $this->assertStringContainsString('data-page=', $response->getBody());
        $this->assertStringContainsString('&quot;component&quot;:&quot;Home&quot;', $response->getBody());

        if (file_exists($template)) {
            unlink($template);
        }
    }

    public function test_shares_global_props(): void
    {
        $request = new Request('GET', '/profile', headers: ['x-inertia' => 'true']);
        $this->inertia->setRequest($request);
        $this->inertia->share('app_name', 'aio Framework');

        $response = $this->inertia->render('Profile', ['role' => 'admin']);
        $data = json_decode($response->getBody(), true);

        $this->assertSame('aio Framework', $data['props']['app_name']);
        $this->assertSame('admin', $data['props']['role']);
    }

    public function test_returns_409_conflict_on_version_mismatch(): void
    {
        $request = new Request(
            method: 'GET',
            uri: '/dashboard',
            headers: [
                'x-inertia' => 'true',
                'x-inertia-version' => '0.9.0', // Stale version
            ]
        );
        $this->inertia->setRequest($request);

        $response = $this->inertia->render('Dashboard');

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('/dashboard', $response->getHeader('X-Inertia-Location'));
    }
}
