<?php

declare(strict_types=1);

namespace App\Modules\Settings\Controllers;

use App\Modules\Auth\Services\AuthService;
use Core\Audit\AuditLogger;
use Core\Http\Request;
use Core\Http\Response;
use Core\Http\Session;
use Core\Inertia\Inertia;
use Core\Validation\ValidatesRequests;
use Core\Webhook\WebhookDispatcher;

class WebhookController
{
    use ValidatesRequests;

    public function __construct(
        private AuthService $auth,
        private WebhookDispatcher $webhookDispatcher,
        private AuditLogger $auditLogger,
        private Inertia $inertia,
        private Session $session
    ) {
    }

    public function index(Request $request): Response
    {
        $role = $this->auth->currentRole();
        if ($role !== 'owner' && $role !== 'admin' && !$this->auth->isSuperadmin()) {
            $this->session->flash('error', 'Hanya Owner atau Admin yang memiliki akses ke pengaturan Webhook.');
            return Response::redirect('/dashboard');
        }

        $tenant = $this->auth->currentTenant();
        $tenantId = (int) ($tenant?->id ?? 0);

        $webhooks = $this->webhookDispatcher->getForTenant($tenantId);
        $deliveries = $this->webhookDispatcher->getDeliveries($tenantId, limit: 30);

        return $this->inertia->render('Settings/Webhooks', [
            'webhooks' => $webhooks,
            'deliveries' => $deliveries,
            'currentTenant' => $tenant ? [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
            ] : null,
            'flash' => [
                'success' => $this->session->getFlash('success'),
                'error' => $this->session->getFlash('error'),
            ],
        ]);
    }

    public function store(Request $request): Response
    {
        $role = $this->auth->currentRole();
        if ($role !== 'owner' && $role !== 'admin' && !$this->auth->isSuperadmin()) {
            $this->session->flash('error', 'Akses ditolak.');
            return Response::redirect('/dashboard');
        }

        $validated = $this->validate($request, [
            'name' => 'required|string|max:100',
            'url' => 'required|string|max:255',
        ]);

        $url = trim((string) ($validated['url'] ?? ''));
        if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            $this->session->flash('error', 'URL Webhook tidak valid. Pastikan menggunakan format http:// atau https://');
            return Response::redirect('/settings/webhooks');
        }

        $tenant = $this->auth->currentTenant();
        $tenantId = (int) ($tenant?->id ?? 0);

        $eventsInput = $request->input('events');
        $events = ['*'];
        if (is_array($eventsInput) && !empty($eventsInput)) {
            $events = array_values(array_filter($eventsInput, 'is_string'));
        } elseif (is_string($eventsInput) && trim($eventsInput) !== '') {
            $events = array_map('trim', explode(',', $eventsInput));
        }

        $secret = trim((string) $request->input('secret', ''));
        if ($secret === '') {
            $secret = null;
        }

        $created = $this->webhookDispatcher->register(
            $tenantId,
            $validated['name'],
            $validated['url'],
            $events,
            $secret
        );

        $this->auditLogger->log(
            action: 'webhook.registered',
            description: "Mendaftarkan webhook baru: [{$validated['name']}] ke [{$validated['url']}]",
            entityType: 'webhook',
            entityId: (string) $created['id'],
            properties: ['name' => $validated['name'], 'url' => $validated['url'], 'events' => $events]
        );

        $this->session->flash('success', "Webhook [{$validated['name']}] berhasil didaftarkan.");
        return Response::redirect('/settings/webhooks');
    }

    public function toggle(Request $request, string $id): Response
    {
        $role = $this->auth->currentRole();
        if ($role !== 'owner' && $role !== 'admin' && !$this->auth->isSuperadmin()) {
            $this->session->flash('error', 'Akses ditolak.');
            return Response::redirect('/dashboard');
        }

        $tenant = $this->auth->currentTenant();
        $tenantId = (int) ($tenant?->id ?? 0);

        $this->webhookDispatcher->toggle($tenantId, (int) $id);

        $this->session->flash('success', 'Status webhook berhasil diubah.');
        return Response::redirect('/settings/webhooks');
    }

    public function destroy(Request $request, string $id): Response
    {
        $role = $this->auth->currentRole();
        if ($role !== 'owner' && $role !== 'admin' && !$this->auth->isSuperadmin()) {
            $this->session->flash('error', 'Akses ditolak.');
            return Response::redirect('/dashboard');
        }

        $tenant = $this->auth->currentTenant();
        $tenantId = (int) ($tenant?->id ?? 0);

        $this->webhookDispatcher->delete($tenantId, (int) $id);

        $this->auditLogger->log(
            action: 'webhook.deleted',
            description: "Menghapus webhook #{$id}",
            entityType: 'webhook',
            entityId: (string) $id
        );

        $this->session->flash('success', 'Webhook berhasil dihapus.');
        return Response::redirect('/settings/webhooks');
    }

    public function test(Request $request, string $id): Response
    {
        $role = $this->auth->currentRole();
        if ($role !== 'owner' && $role !== 'admin' && !$this->auth->isSuperadmin()) {
            $this->session->flash('error', 'Akses ditolak.');
            return Response::redirect('/dashboard');
        }

        $tenant = $this->auth->currentTenant();
        $tenantId = (int) ($tenant?->id ?? 0);

        $webhook = $this->webhookDispatcher->find($tenantId, (int) $id);
        if (!$webhook) {
            $this->session->flash('error', 'Webhook tidak ditemukan.');
            return Response::redirect('/settings/webhooks');
        }

        $this->webhookDispatcher->dispatch($tenantId, 'ping.test', [
            'test' => true,
            'message' => 'Uji ping webhook dari aio Webhook Center',
            'sent_at' => date('Y-m-d H:i:s'),
            'sender' => $this->auth->user()?->name ?? 'User',
        ]);

        $this->session->flash('success', "Pengiriman uji coba ping untuk [{$webhook['name']}] berhasil dimasukkan ke antrean pekerjaan!");
        return Response::redirect('/settings/webhooks');
    }
}
