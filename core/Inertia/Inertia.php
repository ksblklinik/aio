<?php

declare(strict_types=1);

namespace Core\Inertia;

use Core\Http\Request;
use Core\Http\Response;

class Inertia
{
    private static ?Inertia $instance = null;

    private ?Request $request = null;
    private array $sharedProps = [];
    private string $rootView = '';
    private string $version = '1.0.0';

    public function __construct(?Request $request = null)
    {
        $this->request = $request;
        $this->rootView = dirname(__DIR__, 2) . '/resources/views/app.php';
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function setInstance(?self $instance): void
    {
        self::$instance = $instance;
    }

    public function setRequest(Request $request): self
    {
        $this->request = $request;
        return $this;
    }

    public function setRootView(string $path): self
    {
        $this->rootView = $path;
        return $this;
    }

    public function setVersion(string $version): self
    {
        $this->version = $version;
        return $this;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function share(string|array $key, mixed $value = null): self
    {
        if (is_array($key)) {
            $this->sharedProps = array_merge($this->sharedProps, $key);
        } else {
            $this->sharedProps[$key] = $value;
        }
        return $this;
    }

    public function render(string $component, array $props = []): Response
    {
        $request = $this->request ?? (function_exists('app') ? app(Request::class) : Request::capture());

        // Check Inertia Asset Version mismatch on GET requests
        if ($request->isInertia() && $request->isMethod('GET')) {
            $clientVersion = $request->header('x-inertia-version');
            if ($clientVersion !== null && $clientVersion !== $this->version) {
                return new Response(
                    statusCode: 409,
                    headers: ['X-Inertia-Location' => $request->uri()]
                );
            }
        }

        // Merge shared props with local view props
        $resolvedProps = array_merge($this->sharedProps, $props);

        // Resolve lazy props / callables if any
        foreach ($resolvedProps as $key => $val) {
            if (is_callable($val)) {
                $resolvedProps[$key] = $val();
            }
        }

        $page = [
            'component' => $component,
            'props' => $resolvedProps,
            'url' => $request->uri(),
            'version' => $this->version,
        ];

        // 1. Jika request berasal dari Inertia AJAX visit -> return JSON dengan header X-Inertia
        if ($request->isInertia()) {
            return Response::json($page, 200, [
                'X-Inertia' => 'true',
                'Vary' => 'X-Inertia',
            ]);
        }

        // 2. Jika request adalah full initial page visit -> render template root HTML
        return $this->renderHtmlRoot($page);
    }

    private function renderHtmlRoot(array $page): Response
    {
        if (!file_exists($this->rootView)) {
            // Fallback basic HTML structure
            $pageJson = htmlspecialchars((string) json_encode($page), ENT_QUOTES, 'UTF-8');
            $html = "<!DOCTYPE html><html><head><meta charset=\"utf-8\"><title>aio</title></head><body><div id=\"app\" data-page=\"{$pageJson}\"></div></body></html>";
            return Response::html($html);
        }

        ob_start();
        // Variables accessible inside template: $page
        extract(['page' => $page], EXTR_SKIP);
        include $this->rootView;
        $html = (string) ob_get_clean();

        return Response::html($html);
    }
}
