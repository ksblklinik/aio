<?php

declare(strict_types=1);

namespace App\Modules\Modules\Controllers;

use App\Modules\Auth\Services\AuthService;
use Core\Http\Request;
use Core\Http\Response;
use Core\Http\Session;
use Core\Modules\CategoryRegistry;
use Core\Modules\ModuleManager;
use DomainException;
use Throwable;

class ModuleManagerController
{
    private ModuleManager $moduleManager;
    private AuthService $auth;
    private Session $session;

    public function __construct(ModuleManager $moduleManager, AuthService $auth, Session $session)
    {
        $this->moduleManager = $moduleManager;
        $this->auth = $auth;
        $this->session = $session;
    }

    /**
     * Tampilkan halaman daftar modul (sesuai workspace aktif)
     */
    public function index(Request $request): Response
    {
        $role = $this->auth->currentRole();
        if ($role !== 'owner' && $role !== 'admin') {
            $this->session->flash('error', 'Akses ditolak: Hanya Owner atau Admin yang dapat mengelola modul sistem.');
            return Response::redirect('/dashboard');
        }

        $tenant = $this->auth->currentTenant();
        $modules = $tenant 
            ? $this->moduleManager->listModulesForTenant((int) $tenant->id)
            : $this->moduleManager->listModules();

        return inertia('Modules/Index', [
            'title' => 'Manajer Modul & Ekstensi',
            'modules' => $modules,
            'categories' => CategoryRegistry::all(),
            'currentWorkspace' => $tenant ? [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
            ] : null,
            'isSuperadmin' => $this->auth->isSuperadmin(),
        ]);
    }

    /**
     * Toggle status aktif / nonaktif modul untuk workspace aktif atau server global (Superadmin)
     */
    public function toggle(Request $request): Response
    {
        $role = $this->auth->currentRole();
        $isSuperadmin = $this->auth->isSuperadmin();

        if ($role !== 'owner' && $role !== 'admin' && !$isSuperadmin) {
            $this->session->flash('error', 'Hanya Owner, Admin, atau Superadmin yang dapat mengubah status modul.');
            return Response::redirect('/modules');
        }

        $id = trim((string) $request->input('id', ''));
        if ($id === '') {
            $this->session->flash('error', 'ID modul tidak valid.');
            return Response::redirect('/modules');
        }

        $scope = (string) $request->input('scope', 'tenant');
        $tenant = $this->auth->currentTenant();

        try {
            if ($scope === 'global') {
                if (!$isSuperadmin) {
                    $this->session->flash('error', 'Hanya Platform Superadmin yang dapat mengubah status modul secara global.');
                    return Response::redirect('/modules');
                }

                $updated = $this->moduleManager->toggleGlobalActive($id);
                $statusText = $updated['is_active'] ? 'diaktifkan' : 'dinonaktifkan';
                $this->session->flash('success', "Modul [{$updated['name']}] berhasil {$statusText} di seluruh server (Platform Global).");
            } elseif ($tenant) {
                // Jika Superadmin men-toggle modul yang sedang nonaktif di tingkat sistem, aktifkan kembali di tingkat global!
                if ($isSuperadmin && !$this->moduleManager->isModuleActiveGlobally($id)) {
                    $updated = $this->moduleManager->toggleGlobalActive($id, true);
                    $this->session->flash('success', "Modul [{$updated['name']}] berhasil diaktifkan kembali di seluruh server (Platform Global).");
                    return Response::redirect('/modules');
                }

                $updated = $this->moduleManager->toggleActiveForTenant((int) $tenant->id, $id);
                $statusText = $updated['is_active'] ? 'diaktifkan' : 'dinonaktifkan';
                $this->session->flash('success', "Modul [{$updated['name']}] berhasil {$statusText} untuk workspace '{$tenant->name}'.");
            } else {
                $updated = $this->moduleManager->toggleActive($id);
                $statusText = $updated['is_active'] ? 'diaktifkan' : 'dinonaktifkan';
                $this->session->flash('success', "Modul [{$updated['name']}] berhasil {$statusText} secara global.");
            }
        } catch (DomainException $e) {
            $this->session->flash('error', $e->getMessage());
        } catch (Throwable $e) {
            $this->session->flash('error', 'Terjadi kesalahan sistem saat mengubah status modul.');
        }

        return Response::redirect('/modules');
    }

    /**
     * Buat modul baru via Scaffolding otomatis
     */
    public function create(Request $request): Response
    {
        $role = $this->auth->currentRole();
        if ($role !== 'owner' && $role !== 'admin') {
            $this->session->flash('error', 'Hanya Owner atau Admin yang dapat membuat modul baru.');
            return Response::redirect('/modules');
        }

        $name = trim((string) $request->input('name', ''));
        $id = trim((string) $request->input('id', ''));
        $description = trim((string) $request->input('description', ''));
        $category = CategoryRegistry::normalizeKey(trim((string) $request->input('category', 'collaboration')));
        $icon = trim((string) $request->input('icon', 'Package'));

        if ($name === '') {
            $this->session->flash('error', 'Nama modul wajib diisi.');
            return Response::redirect('/modules');
        }

        try {
            $this->moduleManager->createModuleScaffold([
                'name' => $name,
                'id' => $id ?: strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', $name)),
                'description' => $description,
                'category' => $category,
                'icon' => $icon,
                'author' => $this->auth->user()?->name ?? 'Admin',
            ]);

            $this->session->flash('success', "Modul baru [{$name}] berhasil dibuat dan siap digunakan!");
        } catch (DomainException $e) {
            $this->session->flash('error', $e->getMessage());
        } catch (Throwable $e) {
            $this->session->flash('error', 'Gagal membuat modul: ' . $e->getMessage());
        }

        return Response::redirect('/modules');
    }

    /**
     * Hapus modul secara permanen (Clean Wipe)
     */
    public function destroy(Request $request): Response
    {
        $role = $this->auth->currentRole();
        if ($role !== 'owner') {
            $this->session->flash('error', 'Hanya Owner workspace yang memiliki izin untuk menghapus modul permanen.');
            return Response::redirect('/modules');
        }

        $id = trim((string) $request->input('id', ''));
        if ($id === '') {
            $this->session->flash('error', 'ID modul tidak valid.');
            return Response::redirect('/modules');
        }

        try {
            $this->moduleManager->deleteModule($id);
            $this->session->flash('success', "Modul [{$id}] dan seluruh datanya telah berhasil dihapus bersih (Clean Wipe).");
        } catch (DomainException $e) {
            $this->session->flash('error', $e->getMessage());
        } catch (Throwable $e) {
            $this->session->flash('error', 'Gagal menghapus modul: ' . $e->getMessage());
        }

        return Response::redirect('/modules');
    }
}
