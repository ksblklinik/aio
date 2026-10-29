<?php

declare(strict_types=1);

namespace App\Controllers;

use Core\Http\Request;
use Core\Http\Response;

class HomeController extends Controller
{
    public function index(Request $request): Response
    {
        return $this->render('Welcome', [
            'appName' => config('app.name', 'aio SaaS Engine'),
            'phpVersion' => PHP_VERSION,
        ]);
    }
}
