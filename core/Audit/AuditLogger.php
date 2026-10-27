<?php

declare(strict_types=1);

namespace Core\Audit;

use App\Modules\Auth\Services\AuthService;
use Core\Database\Connection;
use Core\Database\TenantContext;
use Core\Http\Request;

class AuditLogger
{
    public function __construct(
        private Connection $db,
        private ?TenantContext $tenantContext = null,
        private ?AuthService $authService = null,
        private ?Request $request = null
    ) {
    }

    /**
     * Catat log aktivitas baru ke basis data.
     *
     * @param string $action Contoh: 'auth.login', 'module.toggled', 'project.created'
     * @param string $description Ringkasan manusiawi aktivitas
     * @param string|null $entityType Jenis entitas (project, module, user, tenant, task)
     * @param string|null $entityId ID entitas yang dipengaruhi
     * @param array<string, mixed> $properties Detail tambahan / payload perubahan
     * @param int|null $tenantId Override tenant id (jika tidak mengambil dari context)
     * @param int|null $userId Override user id (jika tidak mengambil dari session auth)
     * @param string|null $userName Override nama user
     * @return int ID audit log yang baru dibuat
     */
    public function log(
        string $action,
        string $description,
        ?string $entityType = null,
        ?string $entityId = null,
        array $properties = [],
        ?int $tenantId = null,
        ?int $userId = null,
        ?string $userName = null
    ): int {
        // 1. Tentukan Tenant ID
        if ($tenantId === null && $this->tenantContext && $this->tenantContext->hasTenant()) {
            $contextId = $this->tenantContext->getTenantId();
            $tenantId = is_numeric($contextId) ? (int) $contextId : null;
        }

        // 2. Tentukan User / Actor
        if ($userId === null && $this->authService && $this->authService->user()) {
            $user = $this->authService->user();
            $userId = (int) $user->id;
            if ($userName === null) {
                $userName = $user->name;
            }
        }

        // 3. Metadata Request (IP & User Agent)
        $ipAddress = null;
        $userAgent = null;
        if ($this->request) {
            $ipAddress = $this->request->ip();
            $userAgent = $this->request->userAgent();
        } elseif (isset($_SERVER['REMOTE_ADDR'])) {
            $ipAddress = (string) $_SERVER['REMOTE_ADDR'];
            $userAgent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? 'CLI/Internal');
        } else {
            $ipAddress = '127.0.0.1';
            $userAgent = 'CLI/System';
        }

        $jsonProperties = !empty($properties) ? json_encode($properties, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;

        $createdAt = date('Y-m-d H:i:s');
        $secretKey = (string) ($_ENV['APP_KEY'] ?? 'aio-audit-chain-key');

        // Dapatkan current_hash dari baris terakhir untuk membentuk rantai kriptografis
        $lastRow = $this->db->selectOne("SELECT current_hash FROM audit_logs ORDER BY id DESC LIMIT 1");
        $prevHash = (string) ($lastRow['current_hash'] ?? '0000000000000000000000000000000000000000000000000000000000000000');

        $hashPayload = implode('|', [
            $prevHash,
            $tenantId ?? 'null',
            $userId ?? 'null',
            $action,
            $entityType ?? 'null',
            $entityId ?? 'null',
            $jsonProperties ?? '',
            $createdAt,
        ]);
        $currentHash = hash_hmac('sha256', $hashPayload, $secretKey);

        $insertId = $this->db->insert('audit_logs', [
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'user_name' => $userName,
            'action' => $action,
            'description' => $description,
            'entity_type' => $entityType,
            'entity_id' => $entityId !== null ? (string) $entityId : null,
            'properties' => $jsonProperties,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'prev_hash' => $prevHash,
            'current_hash' => $currentHash,
            'created_at' => $createdAt,
        ]);

        return (int) $insertId;
    }

    /**
     * Verifikasi integritas rantai audit log (Tamper-Evident Verification).
     * Mendeteksi jika ada baris log yang diubah langsung atau dihapus di basis data.
     *
     * @return array{valid: bool, broken_at_id: int|null, reason: string|null, verified_count: int}
     */
    public function verifyChain(): array
    {
        $rows = $this->db->selectAll("SELECT * FROM audit_logs WHERE current_hash IS NOT NULL ORDER BY id ASC");
        if (empty($rows)) {
            return [
                'valid' => true,
                'broken_at_id' => null,
                'reason' => null,
                'verified_count' => 0,
            ];
        }

        $secretKey = (string) ($_ENV['APP_KEY'] ?? 'aio-audit-chain-key');
        $expectedPrevHash = (string) $rows[0]['prev_hash'];
        $verifiedCount = 0;

        foreach ($rows as $row) {
            $rowId = (int) $row['id'];
            $storedPrev = (string) ($row['prev_hash'] ?? '');
            $storedCurrent = (string) ($row['current_hash'] ?? '');

            // 1. Verifikasi kontinuitas rantai dari hash sebelumnya
            if ($storedPrev !== $expectedPrevHash) {
                return [
                    'valid' => false,
                    'broken_at_id' => $rowId,
                    'reason' => 'Previous hash pointer broken (missing or deleted log record)',
                    'verified_count' => $verifiedCount,
                ];
            }

            // 2. Verifikasi keaslian isi data baris ini
            $jsonProps = $row['properties'];
            $recalculatedPayload = implode('|', [
                $storedPrev,
                $row['tenant_id'] ?? 'null',
                $row['user_id'] ?? 'null',
                $row['action'],
                $row['entity_type'] ?? 'null',
                $row['entity_id'] ?? 'null',
                $jsonProps ?? '',
                $row['created_at'],
            ]);
            $expectedCurrentHash = hash_hmac('sha256', $recalculatedPayload, $secretKey);

            if (!hash_equals($storedCurrent, $expectedCurrentHash)) {
                return [
                    'valid' => false,
                    'broken_at_id' => $rowId,
                    'reason' => 'Log content has been modified directly in database',
                    'verified_count' => $verifiedCount,
                ];
            }

            $expectedPrevHash = $storedCurrent;
            $verifiedCount++;
        }

        return [
            'valid' => true,
            'broken_at_id' => null,
            'reason' => null,
            'verified_count' => $verifiedCount,
        ];
    }

    /**
     * Ambil riwayat log aktivitas yang terisolasi untuk suatu tenant.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getForTenant(int $tenantId, int $limit = 50, int $offset = 0): array
    {
        $rows = $this->db->selectAll(
            "SELECT * FROM audit_logs 
             WHERE tenant_id = ? 
             ORDER BY id DESC 
             LIMIT ? OFFSET ?",
            [$tenantId, $limit, $offset]
        );

        return array_map([$this, 'formatLogEntry'], $rows);
    }

    /**
     * Ambil riwayat log aktivitas global (misal untuk platform superadmin atau CLI).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRecent(int $limit = 50, int $offset = 0): array
    {
        $rows = $this->db->selectAll(
            "SELECT a.*, t.name as tenant_name 
             FROM audit_logs a
             LEFT JOIN tenants t ON t.id = a.tenant_id
             ORDER BY a.id DESC 
             LIMIT ? OFFSET ?",
            [$limit, $offset]
        );

        return array_map([$this, 'formatLogEntry'], $rows);
    }

    public function countForTenant(int $tenantId): int
    {
        $res = $this->db->selectOne("SELECT COUNT(id) as total FROM audit_logs WHERE tenant_id = ?", [$tenantId]);
        return (int) ($res['total'] ?? 0);
    }

    /**
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private function formatLogEntry(array $entry): array
    {
        if (isset($entry['properties']) && is_string($entry['properties'])) {
            $entry['properties'] = json_decode($entry['properties'], true) ?: [];
        } else {
            $entry['properties'] = [];
        }

        // Tentukan warna badge dan label kategori berdasarkan action prefix
        $action = (string) ($entry['action'] ?? '');
        $category = explode('.', $action)[0] ?? 'system';

        $badges = [
            'auth' => ['color' => 'indigo', 'label' => 'Autentikasi'],
            'module' => ['color' => 'purple', 'label' => 'Modul'],
            'project' => ['color' => 'emerald', 'label' => 'Proyek'],
            'task' => ['color' => 'cyan', 'label' => 'Tugas'],
            'tenant' => ['color' => 'amber', 'label' => 'Workspace'],
            'user' => ['color' => 'blue', 'label' => 'Pengguna'],
        ];

        $entry['badge'] = $badges[$category] ?? ['color' => 'slate', 'label' => ucfirst($category)];

        return $entry;
    }
}
