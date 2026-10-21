<?php

declare(strict_types=1);

namespace App\Modules\Activity\Controllers;

use App\Modules\Auth\Services\AuthService;
use Core\Audit\AuditLogger;
use Core\Http\Request;
use Core\Http\Response;
use Core\Http\Session;
use Core\Inertia\Inertia;

class ActivityLogController
{
    public function __construct(
        private AuthService $auth,
        private AuditLogger $auditLogger,
        private Inertia $inertia,
        private Session $session
    ) {
    }

    public function index(Request $request): Response
    {
        $tenant = $this->auth->currentTenant();
        $isSuperadmin = $this->auth->isSuperadmin();
        $scope = (string) $request->query('scope', 'tenant');

        if ($scope === 'global' && $isSuperadmin) {
            $logs = $this->auditLogger->getRecent(50);
            $total = count($logs);
        } elseif ($tenant) {
            $logs = $this->auditLogger->getForTenant((int) $tenant->id, 50);
            $total = $this->auditLogger->countForTenant((int) $tenant->id);
        } else {
            $logs = [];
            $total = 0;
        }

        return $this->inertia->render('Activity/Index', [
            'logs' => $logs,
            'totalLogs' => $total,
            'activeScope' => ($scope === 'global' && $isSuperadmin) ? 'global' : 'tenant',
            'currentTenant' => $tenant ? [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
            ] : null,
            'isSuperadmin' => $isSuperadmin,
        ]);
    }
}
