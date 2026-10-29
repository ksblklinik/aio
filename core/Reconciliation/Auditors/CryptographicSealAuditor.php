<?php

declare(strict_types=1);

namespace Core\Reconciliation\Auditors;

use Core\Database\Connection;
use Core\Reconciliation\Discrepancy;
use Core\Reconciliation\PillarAuditResult;
use Throwable;

class CryptographicSealAuditor implements AuditorInterface
{
    public function __construct(
        private Connection $db,
        private ?string $appKey = null
    ) {
        $this->appKey = $appKey ?? \Core\Security\AppKey::get();
    }

    public function getPillarKey(): string
    {
        return 'seal';
    }

    public function getPillarName(): string
    {
        return 'Cryptographic Document Seal & Anti-Tamper Integrity';
    }

    public function audit(?int $tenantId = null): PillarAuditResult
    {
        $discrepancies = [];
        $inspectedCount = 0;
        $tamperedCount = 0;

        $sql = "
            SELECT id, tenant_id, verification_token, document_type, document_number, title,
                   payload_snapshot, canonical_hash, signature, status
            FROM document_seals
        ";
        $params = [];
        if ($tenantId !== null) {
            $sql .= " WHERE tenant_id = ?";
            $params[] = $tenantId;
        }

        try {
            $seals = $this->db->selectAll($sql, $params);
            foreach ($seals as $seal) {
                $inspectedCount++;
                $tId = (int) $seal['tenant_id'];
                $payloadJson = (string) $seal['payload_snapshot'];
                $storedHash = (string) $seal['canonical_hash'];
                $storedSig = (string) $seal['signature'];

                // 1. Verify canonical SHA-256 hash
                $calculatedHash = hash('sha256', $payloadJson);
                if (!hash_equals($storedHash, $calculatedHash)) {
                    $tamperedCount++;
                    $discrepancies[] = new Discrepancy(
                        tenantId: $tId,
                        pillar: 'seal',
                        severity: 'critical',
                        entityType: 'document_seal',
                        entityId: (string) $seal['id'],
                        description: "Payload dokumen {$seal['document_type']} #{$seal['document_number']} dimanipulasi: Hash kanonikal tidak cocok dengan berkas asli.",
                        expectedValue: $storedHash,
                        actualValue: $calculatedHash,
                        autoResolvable: false
                    );
                    continue; // Skip sig check if hash is already tampered
                }

                // 2. Verify HMAC SHA-256 Signature
                $secretKey = hash_hmac('sha256', (string) $tId, $this->appKey ?? 'aio-signature-salt-secret-default-key-2026');
                $calculatedSig = hash_hmac('sha256', $calculatedHash, $secretKey);

                if (!hash_equals($storedSig, $calculatedSig)) {
                    $tamperedCount++;
                    $discrepancies[] = new Discrepancy(
                        tenantId: $tId,
                        pillar: 'seal',
                        severity: 'critical',
                        entityType: 'document_seal',
                        entityId: (string) $seal['id'],
                        description: "Tanda tangan kriptografis dokumen {$seal['document_type']} #{$seal['document_number']} tidak valid atau kunci enkripsi berubah.",
                        expectedValue: $storedSig,
                        actualValue: $calculatedSig,
                        autoResolvable: false
                    );
                }
            }
        } catch (\Throwable $e) {
            \Core\Log\Logger::getInstance()->warning(static::class . ": " . $e->getMessage());
        }

        return new PillarAuditResult(
            pillar: 'seal',
            name: $this->getPillarName(),
            isBalanced: empty($discrepancies),
            inspectedCount: $inspectedCount,
            discrepancyCount: count($discrepancies),
            discrepancies: $discrepancies,
            metrics: [
                'tampered_seals_count' => $tamperedCount,
            ]
        );
    }

    public function reconcile(?int $tenantId = null, bool $dryRun = false): array
    {
        $audit = $this->audit($tenantId);
        return [
            'resolved' => 0,
            'messages' => [
                sprintf("Cryptographic seals audited: %d compromised signatures detected requiring security inspection.", $audit->discrepancyCount)
            ]
        ];
    }
}
