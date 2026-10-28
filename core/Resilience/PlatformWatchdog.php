<?php

declare(strict_types=1);

namespace Core\Resilience;

use Core\Database\Connection;
use Throwable;

class PlatformWatchdog implements PlatformWatchdogInterface
{
    public function __construct(
        private Connection $db,
        private ?string $dbPath = null
    ) {
        $this->dbPath = $dbPath ?? (dirname(__DIR__, 2) . '/database.sqlite');
    }

    public function checkHealth(): PlatformHealthReport
    {
        $checkedAt = date('Y-m-d H:i:s');

        $queueHealth = $this->inspectQueue();
        $circuitHealth = $this->inspectCircuitBreakers();
        $databaseHealth = $this->inspectDatabase();
        $lockHealth = $this->inspectLocks();
        $dlqHealth = $this->inspectDeadLetterQueue();
        $systemInfo = $this->inspectSystem();

        $statuses = [
            $queueHealth['status'],
            $circuitHealth['status'],
            $databaseHealth['status'],
            $lockHealth['status'],
            $dlqHealth['status'],
        ];

        if (in_array('critical', $statuses, true)) {
            $overallStatus = 'critical';
            $isHealthy = false;
        } elseif (in_array('degraded', $statuses, true)) {
            $overallStatus = 'degraded';
            $isHealthy = false;
        } else {
            $overallStatus = 'healthy';
            $isHealthy = true;
        }

        return new PlatformHealthReport(
            isHealthy: $isHealthy,
            overallStatus: $overallStatus,
            queueHealth: $queueHealth,
            circuitHealth: $circuitHealth,
            databaseHealth: $databaseHealth,
            lockHealth: $lockHealth,
            dlqHealth: $dlqHealth,
            systemInfo: $systemInfo,
            checkedAt: $checkedAt
        );
    }

    public function isHealthy(): bool
    {
        return $this->checkHealth()->isHealthy;
    }

    /**
     * @return array{status: string, message: string, details?: array<string, mixed>}
     */
    private function inspectQueue(): array
    {
        try {
            $totalJobs = (int) ($this->db->selectOne("SELECT COUNT(*) as cnt FROM jobs")['cnt'] ?? 0);
            $staleThreshold = date('Y-m-d H:i:s', time() - 90);
            $staleJobs = (int) ($this->db->selectOne(
                "SELECT COUNT(*) as cnt FROM jobs WHERE reserved_at IS NOT NULL AND reserved_at <= ?",
                [$staleThreshold]
            )['cnt'] ?? 0);

            if ($staleJobs > 0) {
                return [
                    'status' => 'degraded',
                    'message' => sprintf("Terdapat %d pekerjaan antrean yang terhenti / stale (>90 detik reservasi).", $staleJobs),
                    'details' => [
                        'total_jobs' => $totalJobs,
                        'stale_jobs' => $staleJobs,
                    ],
                ];
            }

            return [
                'status' => 'healthy',
                'message' => sprintf("Antrean normal. Total pekerjaan aktif: %d.", $totalJobs),
                'details' => [
                    'total_jobs' => $totalJobs,
                    'stale_jobs' => 0,
                ],
            ];
        } catch (Throwable $e) {
            return [
                'status' => 'critical',
                'message' => 'Gagal menginspeksi tabel jobs: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * @return array{status: string, message: string, details?: array<string, mixed>}
     */
    private function inspectCircuitBreakers(): array
    {
        try {
            $rows = $this->db->selectAll("SELECT service_name, state, failure_count FROM circuit_breakers");
            $openServices = [];
            $halfOpenServices = [];

            foreach ($rows as $r) {
                if ($r['state'] === CircuitBreakerInterface::STATE_OPEN) {
                    $openServices[] = $r['service_name'];
                } elseif ($r['state'] === CircuitBreakerInterface::STATE_HALF_OPEN) {
                    $halfOpenServices[] = $r['service_name'];
                }
            }

            if (!empty($openServices)) {
                return [
                    'status' => 'degraded',
                    'message' => sprintf(
                        "Circuit breaker TERBUKA (fail-fast) pada layanan: %s.",
                        implode(', ', $openServices)
                    ),
                    'details' => [
                        'total_breakers' => count($rows),
                        'open' => $openServices,
                        'half_open' => $halfOpenServices,
                    ],
                ];
            }

            return [
                'status' => 'healthy',
                'message' => sprintf("Seluruh circuit breaker (%d layanan) dalam kondisi tertutup (normal).", count($rows)),
                'details' => [
                    'total_breakers' => count($rows),
                    'open' => [],
                    'half_open' => $halfOpenServices,
                ],
            ];
        } catch (Throwable $e) {
            return [
                'status' => 'healthy',
                'message' => 'Belum ada circuit breaker yang terdaftar.',
                'details' => ['total_breakers' => 0],
            ];
        }
    }

    /**
     * @return array{status: string, message: string, details?: array<string, mixed>}
     */
    private function inspectDatabase(): array
    {
        try {
            $res = $this->db->selectOne("PRAGMA integrity_check(1)");
            $integrity = $res['integrity_check'] ?? 'unknown';

            $walSize = 0;
            $walPath = ($this->dbPath ?? '') . '-wal';
            if (file_exists($walPath)) {
                $walSize = filesize($walPath);
            }

            $dbSize = file_exists($this->dbPath ?? '') ? filesize($this->dbPath) : 0;

            if (strtolower((string) $integrity) !== 'ok') {
                return [
                    'status' => 'critical',
                    'message' => 'Basis data mengalami kerusakan integritas halaman: ' . $integrity,
                    'details' => [
                        'integrity' => $integrity,
                        'db_size' => $dbSize,
                        'wal_size' => $walSize,
                    ],
                ];
            }

            // Warning if WAL > 50MB
            if ($walSize > 50 * 1024 * 1024) {
                return [
                    'status' => 'degraded',
                    'message' => sprintf("Ukuran berkas WAL sangat besar (%.2f MB). Perlu checkpointing.", $walSize / 1048576),
                    'details' => [
                        'integrity' => 'ok',
                        'db_size' => $dbSize,
                        'wal_size' => $walSize,
                    ],
                ];
            }

            return [
                'status' => 'healthy',
                'message' => sprintf("Database SQLite sehat (Integritas OK, Ukuran DB: %.2f MB, WAL: %.2f KB).", $dbSize / 1048576, $walSize / 1024),
                'details' => [
                    'integrity' => 'ok',
                    'db_size' => $dbSize,
                    'wal_size' => $walSize,
                ],
            ];
        } catch (Throwable $e) {
            return [
                'status' => 'critical',
                'message' => 'Gagal mengakses basis data: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * @return array{status: string, message: string, details?: array<string, mixed>}
     */
    private function inspectLocks(): array
    {
        try {
            $now = time();
            $expiredRows = $this->db->selectAll("SELECT key, expires_at FROM locks WHERE expires_at <= ?", [$now]);
            $activeRows = $this->db->selectAll("SELECT key, expires_at FROM locks WHERE expires_at > ?", [$now]);

            if (count($expiredRows) > 20) {
                return [
                    'status' => 'degraded',
                    'message' => sprintf("Terdapat %d lock concurrency kedaluwarsa yang belum dibersihkan.", count($expiredRows)),
                    'details' => [
                        'active_locks' => count($activeRows),
                        'expired_locks' => count($expiredRows),
                    ],
                ];
            }

            return [
                'status' => 'healthy',
                'message' => sprintf("Lock concurrency sehat (%d lock aktif).", count($activeRows)),
                'details' => [
                    'active_locks' => count($activeRows),
                    'expired_locks' => count($expiredRows),
                ],
            ];
        } catch (Throwable $e) {
            return [
                'status' => 'healthy',
                'message' => 'Tabel locks belum diinisialisasi atau kosong.',
                'details' => ['active_locks' => 0],
            ];
        }
    }

    /**
     * @return array{status: string, message: string, details?: array<string, mixed>}
     */
    private function inspectDeadLetterQueue(): array
    {
        try {
            $totalFailed = (int) ($this->db->selectOne("SELECT COUNT(*) as cnt FROM failed_jobs")['cnt'] ?? 0);

            if ($totalFailed > 100) {
                return [
                    'status' => 'critical',
                    'message' => sprintf("Terdapat %d pekerjaan gagal di Dead-Letter Queue (DLQ). Perlu investigasi darurat.", $totalFailed),
                    'details' => ['failed_count' => $totalFailed],
                ];
            } elseif ($totalFailed > 10) {
                return [
                    'status' => 'degraded',
                    'message' => sprintf("Terdapat %d pekerjaan gagal di Dead-Letter Queue (DLQ).", $totalFailed),
                    'details' => ['failed_count' => $totalFailed],
                ];
            }

            return [
                'status' => 'healthy',
                'message' => sprintf("Dead-Letter Queue normal (%d pekerjaan gagal).", $totalFailed),
                'details' => ['failed_count' => $totalFailed],
            ];
        } catch (Throwable $e) {
            return [
                'status' => 'healthy',
                'message' => 'Tabel failed_jobs kosong atau normal.',
                'details' => ['failed_count' => 0],
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function inspectSystem(): array
    {
        return [
            'php_version' => PHP_VERSION,
            'memory_usage_mb' => round(memory_get_usage(true) / 1048576, 2),
            'memory_peak_mb' => round(memory_get_peak_usage(true) / 1048576, 2),
            'os' => PHP_OS,
        ];
    }
}
