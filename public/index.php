<?php

declare(strict_types=1);

// Single Entry Point aio Web Application
define('AIO_START', microtime(true));

// Built-in PHP CLI Server: Layani file statis (CSS, JS, gambar) secara langsung
if (php_sapi_name() === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if ($path !== '/' && file_exists(__DIR__ . $path)) {
        return false;
    }
}

require_once __DIR__ . '/../vendor/autoload.php';

use Core\Foundation\Application;
use Core\Http\Request;

$app = new Application(dirname(__DIR__));
$request = Request::capture();
$response = $app->handleHttp($request);
$response->send();
