<?php

declare(strict_types=1);

namespace Core;

use Closure;
use Core\Http\Request;
use Core\Http\Response;

class Router
{
    /**
     * @var array<string, array<int, array{uri: string, handler: mixed, middlewares: array<int, mixed>, regex: string, paramNames: array<int, string>}>>
     */
    private array $routes = [
        'GET' => [],
        'POST' => [],
        'PUT' => [],
        'PATCH' => [],
        'DELETE' => [],
    ];

    private string $groupPrefix = '';
    private array $groupMiddlewares = [];

    public function __construct(private ?Container $container = null)
    {
        $this->container = $container ?? Container::getInstance();
    }

    public function get(string $uri, mixed $handler, array $middlewares = []): self
    {
        return $this->addRoute('GET', $uri, $handler, $middlewares);
    }

    public function post(string $uri, mixed $handler, array $middlewares = []): self
    {
        return $this->addRoute('POST', $uri, $handler, $middlewares);
    }

    public function put(string $uri, mixed $handler, array $middlewares = []): self
    {
        return $this->addRoute('PUT', $uri, $handler, $middlewares);
    }

    public function patch(string $uri, mixed $handler, array $middlewares = []): self
    {
        return $this->addRoute('PATCH', $uri, $handler, $middlewares);
    }

    public function delete(string $uri, mixed $handler, array $middlewares = []): self
    {
        return $this->addRoute('DELETE', $uri, $handler, $middlewares);
    }

    public function any(string $uri, mixed $handler, array $middlewares = []): self
    {
        foreach (array_keys($this->routes) as $method) {
            $this->addRoute($method, $uri, $handler, $middlewares);
        }
        return $this;
    }

    public function group(array $attributes, callable $callback): void
    {
        $previousPrefix = $this->groupPrefix;
        $previousMiddlewares = $this->groupMiddlewares;

        if (isset($attributes['prefix'])) {
            $this->groupPrefix = rtrim($previousPrefix . '/' . trim($attributes['prefix'], '/'), '/');
        }

        if (isset($attributes['middleware'])) {
            $middlewares = is_array($attributes['middleware']) ? $attributes['middleware'] : [$attributes['middleware']];
            $this->groupMiddlewares = array_merge($previousMiddlewares, $middlewares);
        }

        $callback($this);

        $this->groupPrefix = $previousPrefix;
        $this->groupMiddlewares = $previousMiddlewares;
    }

    private function addRoute(string $method, string $uri, mixed $handler, array $middlewares = []): self
    {
        $fullUri = '/' . trim($this->groupPrefix . '/' . trim($uri, '/'), '/');
        if ($fullUri !== '/') {
            $fullUri = rtrim($fullUri, '/');
        }

        $combinedMiddlewares = array_merge($this->groupMiddlewares, $middlewares);
        [$regex, $paramNames] = $this->compileRouteRegex($fullUri);

        $this->routes[strtoupper($method)][] = [
            'uri' => $fullUri,
            'handler' => $handler,
            'middlewares' => $combinedMiddlewares,
            'regex' => $regex,
            'paramNames' => $paramNames,
        ];

        return $this;
    }

    public function dispatch(Request $request): Response
    {
        $method = $request->method();
        if ($method === 'HEAD') {
            $method = 'GET';
        }
        $path = $request->path();
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        $routesForMethod = $this->routes[$method] ?? [];

        foreach ($routesForMethod as $route) {
            if (preg_match($route['regex'], $path, $matches)) {
                $parameters = [];
                foreach ($route['paramNames'] as $paramName) {
                    if (isset($matches[$paramName])) {
                        $parameters[$paramName] = $matches[$paramName];
                    }
                }

                return $this->runRoutePipeline($route, $request, $parameters);
            }
        }

        return Response::json(['error' => 'Not Found', 'path' => $path], 404);
    }

    private function runRoutePipeline(array $route, Request $request, array $parameters): Response
    {
        $middlewares = $route['middlewares'];
        $handler = $route['handler'];

        $pipeline = array_reduce(
            array_reverse($middlewares),
            function ($next, $middleware) {
                return function (Request $request) use ($next, $middleware) {
                    return $this->executeMiddleware($middleware, $request, $next);
                };
            },
            function (Request $request) use ($handler, $parameters) {
                return $this->executeHandler($handler, $request, $parameters);
            }
        );

        return $pipeline($request);
    }

    private function executeMiddleware(mixed $middleware, Request $request, callable $next): Response
    {
        if (is_callable($middleware)) {
            return $middleware($request, $next);
        }

        if (is_string($middleware) && $this->container) {
            $instance = $this->container->get($middleware);
            if (is_callable([$instance, 'handle'])) {
                return $instance->handle($request, $next);
            }
        }

        return $next($request);
    }

    private function executeHandler(mixed $handler, Request $request, array $parameters): Response
    {
        if ($handler instanceof Closure || is_callable($handler)) {
            $result = call_user_func($handler, $request, ...array_values($parameters));
        } elseif (is_array($handler) && count($handler) === 2) {
            [$class, $method] = $handler;
            $instance = is_string($class) ? $this->container->get($class) : $class;
            $result = call_user_func([$instance, $method], $request, ...array_values($parameters));
        } else {
            $result = $handler;
        }

        if ($result instanceof Response) {
            return $result;
        }

        if (is_array($result) || is_object($result)) {
            return Response::json($result);
        }

        return Response::html((string) $result);
    }

    private function compileRouteRegex(string $uri): array
    {
        $paramNames = [];
        $pattern = preg_replace_callback('/\{([a-zA-Z0-9_]+)\}/', function ($matches) use (&$paramNames) {
            $paramNames[] = $matches[1];
            return '(?P<' . $matches[1] . '>[^/]+)';
        }, $uri);

        $regex = '#^' . $pattern . '$#';
        return [$regex, $paramNames];
    }

    /**
     * @return array<string, array<int, array{uri: string, handler: mixed, middlewares: array<int, mixed>, regex: string, paramNames: array<int, string>}>>
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }
}
