<?php

declare(strict_types=1);

namespace App\Controllers;

use Core\Http\Response;

abstract class Controller
{
    /**
     * Helper untuk merender komponen Inertia dari controller
     */
    protected function render(string $component, array $props = []): Response
    {
        return inertia($component, $props);
    }

    /**
     * Helper untuk mereturn JSON response
     */
    protected function json(mixed $data, int $statusCode = 200): Response
    {
        return Response::json($data, $statusCode);
    }

    /**
     * Helper untuk redirect response
     */
    protected function redirect(string $url, int $statusCode = 302): Response
    {
        return Response::redirect($url, $statusCode);
    }
}
