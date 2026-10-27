<?php

declare(strict_types=1);

namespace Core\Workflow;

use Core\Auth\Gate;
use Core\Database\Connection;
use Core\Database\TenantContext;

class WorkflowManager
{
    protected Connection $db;
    protected TenantContext $tenantContext;
    protected ?Gate $gate;

    /** @var array<string, WorkflowDefinition> */
    protected array $definitions = [];

    public function __construct(Connection $db, TenantContext $tenantContext, ?Gate $gate = null)
    {
        $this->db = $db;
        $this->tenantContext = $tenantContext;
        $this->gate = $gate;
    }

    /**
     * Daftarkan definisi workflow untuk tipe entitas tertentu.
     */
    public function define(string $entityType, WorkflowDefinition|array $definition): WorkflowDefinition
    {
        if (is_array($definition)) {
            $definition = new WorkflowDefinition($entityType, $definition);
        }

        $this->definitions[$entityType] = $definition;
        return $definition;
    }

    /**
     * Dapatkan definisi workflow berdasarkan entity type.
     */
    public function getDefinition(string $entityType): ?WorkflowDefinition
    {
        return $this->definitions[$entityType] ?? null;
    }

    public function hasDefinition(string $entityType): bool
    {
        return isset($this->definitions[$entityType]);
    }

    /**
     * Periksa apakah transisi status diizinkan secara aturan workflow dan guards.
     */
    public function can(
        string $entityType,
        string $fromStatus,
        string $transitionOrToStatus,
        mixed $actor = null,
        mixed $entity = null,
        int|string|null $tenantId = null
    ): bool {
        $def = $this->getDefinition($entityType);
        if ($def === null) {
            return false;
        }

        $trans = $def->findTransition($fromStatus, $transitionOrToStatus);
        if ($trans === null) {
            return false;
        }

        $tenantId = $tenantId ?? $this->tenantContext->getTenantId();
        $actor = $this->resolveActor($actor);

        return $this->evaluateGuards($trans['guards'], $actor, $entity, $tenantId);
    }

    /**
     * Eksekusi transisi status entitas dengan validasi ketat, guard checks, audit log, dan event dispatching.
     *
     * @throws WorkflowException
     */
    public function transition(
        string $entityType,
        int|string $entityId,
        string $fromStatus,
        string $transitionOrToStatus,
        mixed $actor = null,
        ?string $notes = null,
        mixed $entity = null,
        array $metadata = [],
        int|string|null $tenantId = null
    ): array {
        $def = $this->getDefinition($entityType);
        if ($def === null) {
            throw new WorkflowException(
                "Workflow definition tidak ditemukan untuk tipe entitas: '{$entityType}'",
                $entityType,
                $fromStatus,
                $transitionOrToStatus,
                422
            );
        }

        $trans = $def->findTransition($fromStatus, $transitionOrToStatus);
        if ($trans === null) {
            throw new WorkflowException(
                "Transisi status dari [{$fromStatus}] ke [{$transitionOrToStatus}] tidak diizinkan untuk entitas [{$entityType}]",
                $entityType,
                $fromStatus,
                $transitionOrToStatus,
                422
            );
        }

        $tenantId = $tenantId ?? $this->tenantContext->getTenantId() ?? 1;
        $actor = $this->resolveActor($actor);

        if (!$this->evaluateGuards($trans['guards'], $actor, $entity, $tenantId)) {
            throw new WorkflowException(
                "Akses transisi workflow ditolak oleh guard untuk entitas [{$entityType}] dari status [{$fromStatus}]",
                $entityType,
                $fromStatus,
                $trans['to'],
                403
            );
        }

        $actorId = null;
        $actorName = null;
        if (is_int($actor)) {
            $actorId = $actor;
        } elseif (is_object($actor)) {
            $actorId = isset($actor->id) ? (int) $actor->id : null;
            $actorName = $actor->name ?? $actor->email ?? null;
        }

        $metadataJson = !empty($metadata) ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null;

        // Catat jejak audit transisi ke database
        $this->db->statement(
            "INSERT INTO workflow_transitions (
                tenant_id, entity_type, entity_id, from_status, to_status,
                transition_name, actor_id, actor_name, notes, metadata, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now'))",
            [
                $tenantId,
                $entityType,
                (string) $entityId,
                $fromStatus,
                $trans['to'],
                $trans['name'],
                $actorId,
                $actorName,
                $notes,
                $metadataJson,
            ]
        );

        $payload = [
            'tenant_id' => $tenantId,
            'entity_type' => $entityType,
            'entity_id' => (string) $entityId,
            'from' => $fromStatus,
            'to' => $trans['to'],
            'transition' => $trans['name'],
            'actor_id' => $actorId,
            'actor_name' => $actorName,
            'notes' => $notes,
            'metadata' => $metadata,
            'timestamp' => date('Y-m-d H:i:s'),
        ];

        // Trigger Event Dispatcher jika tersedia
        if (function_exists('event_dispatch')) {
            \event_dispatch("workflow.{$entityType}.transitioned", $payload);
            \event_dispatch("workflow.transitioned", $payload);
        }

        return $payload;
    }

    /**
     * Ambil riwayat mutasi status (audit trail) untuk entitas tertentu.
     */
    public function getHistory(string $entityType, int|string $entityId, int|string|null $tenantId = null): array
    {
        $tenantId = $tenantId ?? $this->tenantContext->getTenantId();

        $sql = "SELECT * FROM workflow_transitions WHERE entity_type = ? AND entity_id = ?";
        $params = [$entityType, (string) $entityId];

        if ($tenantId !== null) {
            $sql .= " AND tenant_id = ?";
            $params[] = $tenantId;
        }

        $sql .= " ORDER BY id ASC";

        $rows = $this->db->selectAll($sql, $params);

        return array_map(function ($row) {
            if (!empty($row['metadata'])) {
                $row['metadata'] = json_decode($row['metadata'], true);
            }
            return $row;
        }, $rows);
    }

    /**
     * Resolusi objek atau ID actor yang sedang aktif.
     */
    protected function resolveActor(mixed $actor): mixed
    {
        if ($actor !== null) {
            return $actor;
        }

        if (function_exists('auth')) {
            try {
                $authService = \auth();
                if ($authService && method_exists($authService, 'check') && $authService->check()) {
                    return $authService->user();
                }
            } catch (\Throwable $e) {
                // Abaikan jika auth tidak terikat pada konteks testing/cli
            }
        }

        return null;
    }

    /**
     * Evaluasi seluruh guards pada sebuah transisi.
     */
    protected function evaluateGuards(array $guards, mixed $actor, mixed $entity, int|string|null $tenantId): bool
    {
        foreach ($guards as $guard) {
            if (is_callable($guard)) {
                $passed = (bool) $guard($actor, $entity, $tenantId);
                if (!$passed) {
                    return false;
                }
                continue;
            }

            if (is_string($guard)) {
                $permission = str_starts_with($guard, 'can:') ? substr($guard, 4) : $guard;

                if ($this->gate !== null) {
                    $intTenant = $tenantId !== null ? (int) $tenantId : null;
                    $userForGate = ($actor instanceof \App\Modules\Workspaces\Models\User || is_int($actor) || $actor === null)
                        ? $actor
                        : (isset($actor->id) ? (int) $actor->id : null);

                    if (!$this->gate->allows($userForGate, $permission, $intTenant)) {
                        return false;
                    }
                } elseif (is_object($actor) && method_exists($actor, 'hasPermission')) {
                    if (!$actor->hasPermission($permission, $tenantId)) {
                        return false;
                    }
                }
            }
        }

        return true;
    }
}
