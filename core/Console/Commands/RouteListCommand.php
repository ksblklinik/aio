<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Console\Command;
use Core\Router;

class RouteListCommand extends Command
{
    protected string $signature = 'route:list';
    protected string $description = 'Menampilkan tabel seluruh endpoint rute HTTP yang terdaftar di aplikasi';

    public function handle(array $arguments = [], array $options = []): int
    {
        /** @var Router $router */
        $router = $this->container->get(Router::class);
        $routes = $router->getRoutes();

        $rows = [];
        $total = 0;

        foreach ($routes as $method => $methodRoutes) {
            foreach ($methodRoutes as $r) {
                $total++;
                $handlerStr = 'Closure';
                if (is_array($r['handler'])) {
                    $class = is_object($r['handler'][0]) ? get_class($r['handler'][0]) : (string) $r['handler'][0];
                    $methodName = (string) ($r['handler'][1] ?? '__invoke');
                    $parts = explode('\\', $class);
                    $shortClass = end($parts);
                    $handlerStr = "{$shortClass}@{$methodName}";
                } elseif (is_string($r['handler'])) {
                    $handlerStr = $r['handler'];
                }

                $methodColor = match ($method) {
                    'GET' => "\033[32mGET\033[0m",
                    'POST' => "\033[34mPOST\033[0m",
                    'PUT', 'PATCH' => "\033[33m{$method}\033[0m",
                    'DELETE' => "\033[31mDELETE\033[0m",
                    default => $method,
                };

                $middlewaresCount = count($r['middlewares']);

                $rows[] = [
                    'Metode' => $methodColor,
                    'URI' => $r['uri'],
                    'Handler' => $handlerStr,
                    'Middleware' => $middlewaresCount > 0 ? "({$middlewaresCount} guards)" : '-',
                ];
            }
        }

        if (empty($rows)) {
            $this->warn("Belum ada route yang terdaftar.");
            return 0;
        }

        $this->table(['Metode', 'URI', 'Handler', 'Middleware'], $rows);
        $this->info("Total: {$total} rute terdaftar.");
        return 0;
    }
}
