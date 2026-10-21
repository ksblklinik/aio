<?php

declare(strict_types=1);

namespace App\Controllers;

use Core\Database\TenantContext;
use Core\Database\TenantScope;
use Core\Http\Request;
use Core\Http\Response;

class DashboardController extends Controller
{
    public function __construct(
        private ?TenantContext $tenantContext = null,
        private ?TenantScope $tenantScope = null
    ) {
        $this->tenantContext = $tenantContext ?? (function_exists('app') ? app(TenantContext::class) : TenantContext::getInstance());
        $this->tenantScope = $tenantScope ?? (function_exists('app') ? app(TenantScope::class) : null);
    }

    public function index(Request $request): Response
    {
        $tenantId = $this->tenantContext->getTenantId() ?? 'tenant-acme';
        $tenantData = $this->tenantContext->getTenantData();

        // Ambil data projects yang terisolasi khusus tenant ini
        $scopedProjects = [];
        if ($this->tenantScope) {
            $rows = $this->tenantScope->selectAll('projects');
            foreach ($rows as $row) {
                $scopedProjects[] = [
                    'id' => (string) $row['id'],
                    'name' => $row['name'],
                    'status' => $row['status'],
                    'recordsCount' => (int) $row['records_count'],
                    'updatedAt' => $row['updated_at'] ?? 'Baru saja',
                ];
            }
        }

        return $this->render('Dashboard', [
            'title' => 'Dashboard Overview',
            'tenant' => [
                'id' => $tenantId,
                'name' => $tenantData['name'] ?? 'Acme Cloud Platform',
                'plan' => $tenantData['plan'] ?? 'Enterprise Tier',
            ],
            'metrics' => [
                'mrr' => '$28,450',
                'activeUsers' => '2,840',
                'apiRequests' => '12.8M req',
                'avgLatency' => '0.8 ms',
            ],
            'projects' => $scopedProjects,
        ]);
    }
}
