<?php

declare(strict_types=1);

namespace App\Modules\Notification\Controllers;

use App\Modules\Auth\Services\AuthService;
use Core\Http\Request;
use Core\Http\Response;
use Core\Http\Session;
use Core\Inertia\Inertia;
use Core\Notification\NotificationService;

class NotificationController
{
    public function __construct(
        private AuthService $auth,
        private NotificationService $notificationService,
        private Inertia $inertia,
        private Session $session
    ) {
    }

    /**
     * Tampilkan halaman pusat notifikasi.
     */
    public function index(Request $request): Response
    {
        $user = $this->auth->user();
        $tenant = $this->auth->currentTenant();

        if (!$user || !$tenant) {
            return Response::redirect('/login');
        }

        $tenantId = (int) $tenant->id;
        $userId = (int) $user->id;
        $filter = (string) $request->query('filter', 'all'); // 'all' | 'unread'
        $unreadOnly = $filter === 'unread';

        $notifications = $this->notificationService->getForUser($tenantId, $userId, 50, $unreadOnly);
        $unreadCount = $this->notificationService->getUnreadCount($tenantId, $userId);

        return $this->inertia->render('Notifications/Index', [
            'notifications' => $notifications,
            'unreadCount' => $unreadCount,
            'activeFilter' => $filter,
        ]);
    }

    /**
     * Tandai sebuah notifikasi telah dibaca.
     */
    public function markAsRead(Request $request, string $id): Response
    {
        $user = $this->auth->user();
        $tenant = $this->auth->currentTenant();

        if (!$user || !$tenant) {
            return Response::json(['error' => 'Unauthorized'], 401);
        }

        $this->notificationService->markAsRead((int) $tenant->id, (int) $user->id, (int) $id);

        if ($request->wantsJson()) {
            return Response::json([
                'success' => true,
                'unread_count' => $this->notificationService->getUnreadCount((int) $tenant->id, (int) $user->id)
            ]);
        }

        return Response::redirect('/notifications');
    }

    /**
     * Tandai semua notifikasi telah dibaca.
     */
    public function markAllAsRead(Request $request): Response
    {
        $user = $this->auth->user();
        $tenant = $this->auth->currentTenant();

        if (!$user || !$tenant) {
            return Response::json(['error' => 'Unauthorized'], 401);
        }

        $this->notificationService->markAllAsRead((int) $tenant->id, (int) $user->id);
        $this->session->flash('success', 'Seluruh notifikasi telah ditandai sebagai dibaca.');

        if ($request->wantsJson()) {
            return Response::json(['success' => true, 'unread_count' => 0]);
        }

        return Response::redirect('/notifications');
    }

    /**
     * Hapus sebuah notifikasi.
     */
    public function delete(Request $request, string $id): Response
    {
        $user = $this->auth->user();
        $tenant = $this->auth->currentTenant();

        if (!$user || !$tenant) {
            return Response::json(['error' => 'Unauthorized'], 401);
        }

        $this->notificationService->delete((int) $tenant->id, (int) $user->id, (int) $id);
        $this->session->flash('success', 'Notifikasi berhasil dihapus.');

        if ($request->wantsJson()) {
            return Response::json(['success' => true]);
        }

        return Response::redirect('/notifications');
    }
}
