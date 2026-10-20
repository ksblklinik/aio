<?php

declare(strict_types=1);

namespace App\Controllers;

use Core\Http\Request;
use Core\Http\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        return $this->render('Dashboard', [
            'title' => 'Dashboard Overview',
            'tenant' => [
                'id' => 'tenant-acme',
                'name' => 'Acme Cloud Platform',
                'plan' => 'Enterprise Tier',
            ],
            'metrics' => [
                'mrr' => '$28,450',
                'activeUsers' => '2,840',
                'apiRequests' => '12.8M req',
                'avgLatency' => '0.8 ms',
            ],
            'projects' => [
                [
                    'id' => '1',
                    'name' => 'Customer Auth & RBAC Engine',
                    'status' => 'Active',
                    'recordsCount' => 24100,
                    'updatedAt' => '2 menit yang lalu',
                ],
                [
                    'id' => '2',
                    'name' => 'Subscription Metering Pipeline',
                    'status' => 'Active',
                    'recordsCount' => 67300,
                    'updatedAt' => '8 menit yang lalu',
                ],
                [
                    'id' => '3',
                    'name' => 'Realtime SSE WebSocket Push',
                    'status' => 'Processing',
                    'recordsCount' => 1420,
                    'updatedAt' => '25 menit yang lalu',
                ],
                [
                    'id' => '4',
                    'name' => 'Audit Log Immutable Storage',
                    'status' => 'Active',
                    'recordsCount' => 189200,
                    'updatedAt' => '1 jam yang lalu',
                ],
            ],
        ]);
    }
}
