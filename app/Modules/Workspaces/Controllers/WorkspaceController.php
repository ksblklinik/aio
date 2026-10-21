<?php

declare(strict_types=1);

namespace App\Modules\Workspaces\Controllers;

use App\Modules\Auth\Services\AuthService;
use Core\Database\Connection;
use Core\Http\Request;
use Core\Http\Response;
use Core\Http\Session;

class WorkspaceController
{
    public function __construct(
        private AuthService $auth,
        private Session $session,
        private Connection $db
    ) {
    }

    public function switchWorkspace(Request $request): Response
    {
        $tenantId = (int) $request->input('tenant_id', 0);
        if ($tenantId <= 0) {
            $this->session->flash('error', 'ID Workspace tidak valid.');
            return Response::redirect('/dashboard');
        }

        if ($this->auth->switchTenant($tenantId)) {
            $tenant = $this->auth->currentTenant();
            $this->session->flash('success', "Berhasil beralih ke workspace: {$tenant?->name}");
        } else {
            $this->session->flash('error', 'Anda tidak memiliki akses ke workspace tersebut.');
        }

        return Response::redirect('/dashboard');
    }
}
