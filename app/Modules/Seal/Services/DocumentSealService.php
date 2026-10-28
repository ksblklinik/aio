<?php

declare(strict_types=1);

namespace App\Modules\Seal\Services;

use App\Modules\Seal\Contracts\DocumentSealRegistryInterface;
use Core\Database\Connection;
use Core\Database\TenantContext;
use InvalidArgumentException;
use PDO;

class DocumentSealService implements DocumentSealRegistryInterface
{
    public function __construct(
        protected Connection $db,
        protected TenantContext $tenantContext
    ) {}

    protected function getTenantId(): int
    {
        $id = $this->tenantContext->getTenantId();
        return $id !== null ? (int)$id : 1;
    }

    public function sealDocument(array $data): array
    {
        $tenantId = $this->getTenantId();

        if (empty($data['document_number']) || empty($data['title'])) {
            throw new InvalidArgumentException("Nomor dokumen dan Judul dokumen wajib diisi.");
        }

        if (empty($data['signer_name']) || empty($data['subject_name'])) {
            throw new InvalidArgumentException("Nama penandatangan dan Nama subjek/penerima wajib diisi.");
        }

        $documentType = !empty($data['document_type']) ? strtolower(trim((string)$data['document_type'])) : 'custom';
        $documentNumber = trim((string)$data['document_number']);
        $title = trim((string)$data['title']);
        $issuerName = !empty($data['issuer_name']) ? trim((string)$data['issuer_name']) : 'Institusi Resmi';
        $signerName = trim((string)$data['signer_name']);
        $signerRole = !empty($data['signer_role']) ? trim((string)$data['signer_role']) : null;
        $signerCredential = !empty($data['signer_credential']) ? trim((string)$data['signer_credential']) : null;
        $subjectName = trim((string)$data['subject_name']);
        $subjectIdentifier = !empty($data['subject_identifier']) ? trim((string)$data['subject_identifier']) : null;

        $subjectMaskedName = !empty($data['subject_masked_name']) 
            ? trim((string)$data['subject_masked_name']) 
            : $this->maskSubjectName($subjectName);

        $payload = is_array($data['payload'] ?? null) ? $data['payload'] : ['document_number' => $documentNumber];
        $canonicalJson = $this->canonicalizePayload($payload);
        $canonicalHash = hash('sha256', $canonicalJson);

        // HMAC signature
        $secretKey = $this->getTenantSignatureSecret($tenantId);
        $signature = hash_hmac('sha256', $canonicalHash, $secretKey);

        $verificationToken = bin2hex(random_bytes(16)); // 32 hex chars
        $validFrom = !empty($data['valid_from']) ? trim((string)$data['valid_from']) : null;
        $validUntil = !empty($data['valid_until']) ? trim((string)$data['valid_until']) : null;
        $metadataJson = !empty($data['metadata']) ? json_encode($data['metadata'], JSON_UNESCAPED_UNICODE) : null;

        $stmt = $this->db->pdo()->prepare("
            INSERT INTO document_seals (
                tenant_id, verification_token, document_type, document_number, title,
                issuer_name, signer_name, signer_role, signer_credential,
                subject_name, subject_identifier, subject_masked_name,
                payload_snapshot, canonical_hash, signature, status,
                valid_from, valid_until, metadata, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([
            $tenantId, $verificationToken, $documentType, $documentNumber, $title,
            $issuerName, $signerName, $signerRole, $signerCredential,
            $subjectName, $subjectIdentifier, $subjectMaskedName,
            $canonicalJson, $canonicalHash, $signature,
            $validFrom, $validUntil, $metadataJson
        ]);

        $newId = (int)$this->db->pdo()->lastInsertId();
        return $this->getSeal($newId);
    }

    public function getSeal(int $id): ?array
    {
        $tenantId = $this->getTenantId();
        $stmt = $this->db->pdo()->prepare("SELECT * FROM document_seals WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$id, $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        return $this->formatSealRow($row);
    }

    public function getSealByToken(string $token): ?array
    {
        $tenantId = $this->getTenantId();
        $token = trim($token);
        $stmt = $this->db->pdo()->prepare("SELECT * FROM document_seals WHERE verification_token = ? AND tenant_id = ?");
        $stmt->execute([$token, $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        return $this->formatSealRow($row);
    }

    public function verifyPublicDocument(string $token, array $requestMeta = []): array
    {
        $token = trim($token);
        $stmt = $this->db->pdo()->prepare("SELECT * FROM document_seals WHERE verification_token = ?");
        $stmt->execute([$token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return [
                'status' => 'not_found',
                'is_valid' => false,
                'message' => 'Dokumen tidak ditemukan atau token verifikasi tidak terdaftar pada sistem resmi.',
                'document' => null,
            ];
        }

        $today = date('Y-m-d');
        $resultStatus = 'success';
        $isValid = true;
        $message = 'Dokumen Resmi, Sah dan Terverifikasi Asli.';

        if ($row['status'] === 'revoked') {
            $resultStatus = 'revoked';
            $isValid = false;
            $message = 'Dokumen ini telah DICABUT (Revoked) dan dinyatakan tidak berlaku lagi oleh penerbit resmi.';
        } elseif (!empty($row['valid_until']) && $today > $row['valid_until']) {
            $resultStatus = 'expired';
            $isValid = false;
            $message = 'Masa berlaku dokumen ini telah BERAKHIR (Expired).';
        }

        // Record verification audit log
        try {
            $logStmt = $this->db->pdo()->prepare("
                INSERT INTO document_verification_logs (
                    document_seal_id, verification_token, verification_result,
                    ip_address, user_agent, referer, verified_at
                ) VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
            ");
            $logStmt->execute([
                $row['id'],
                $token,
                $resultStatus,
                $requestMeta['ip'] ?? null,
                $requestMeta['user_agent'] ?? null,
                $requestMeta['referer'] ?? null,
            ]);
        } catch (\Throwable) {
            // Non-blocking log failure
        }

        $publicDoc = [
            'document_type' => $row['document_type'],
            'document_number' => $row['document_number'],
            'title' => $row['title'],
            'issuer_name' => $row['issuer_name'],
            'signer_name' => $row['signer_name'],
            'signer_role' => $row['signer_role'],
            'signer_credential' => $row['signer_credential'],
            'subject_name' => $row['subject_masked_name'] ?: $this->maskSubjectName((string)$row['subject_name']),
            'subject_identifier' => $row['subject_identifier'] ? $this->maskIdentifier((string)$row['subject_identifier']) : null,
            'status' => $resultStatus,
            'canonical_hash' => $row['canonical_hash'],
            'issued_at' => $row['created_at'],
            'valid_from' => $row['valid_from'],
            'valid_until' => $row['valid_until'],
            'revoked_at' => $row['revoked_at'],
            'revocation_reason' => $row['revocation_reason'],
        ];

        try {
            if (function_exists('sse')) {
                sse('seal', 'seal_verified', [
                    'verification_token' => $token,
                    'document_number' => $row['document_number'],
                    'document_type' => $row['document_type'],
                    'status' => $resultStatus,
                    'is_valid' => $isValid,
                    'ip_address' => $requestMeta['ip'] ?? null,
                    'verified_at' => date('c'),
                ], (string)$row['tenant_id']);
            }
        } catch (\Throwable) {}

        return [
            'status' => $resultStatus,
            'is_valid' => $isValid,
            'message' => $message,
            'document' => $publicDoc,
        ];
    }

    public function revokeSeal(string $token, string $reason): array
    {
        $existing = $this->getSealByToken($token);
        if (!$existing) {
            throw new InvalidArgumentException("Segel dokumen dengan token tersebut tidak ditemukan.");
        }

        $reason = trim($reason);
        if (empty($reason)) {
            throw new InvalidArgumentException("Alasan pencabutan (revocation reason) wajib diisi.");
        }

        $tenantId = $this->getTenantId();
        $stmt = $this->db->pdo()->prepare("
            UPDATE document_seals SET
                status = 'revoked',
                revoked_at = CURRENT_TIMESTAMP,
                revocation_reason = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE verification_token = ? AND tenant_id = ?
        ");
        $stmt->execute([$reason, $token, $tenantId]);

        return $this->getSealByToken($token);
    }

    public function listSeals(array $filters = []): array
    {
        $tenantId = $this->getTenantId();
        $sql = "SELECT * FROM document_seals WHERE tenant_id = ?";
        $params = [$tenantId];

        if (!empty($filters['document_type'])) {
            $sql .= " AND document_type = ?";
            $params[] = strtolower(trim((string)$filters['document_type']));
        }

        if (!empty($filters['status'])) {
            $sql .= " AND status = ?";
            $params[] = strtolower(trim((string)$filters['status']));
        }

        if (!empty($filters['search'])) {
            $search = '%' . trim((string)$filters['search']) . '%';
            $sql .= " AND (document_number LIKE ? OR title LIKE ? OR subject_name LIKE ? OR signer_name LIKE ?)";
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }

        $sql .= " ORDER BY created_at DESC";

        if (!empty($filters['limit'])) {
            $limit = max(1, (int)$filters['limit']);
            $offset = isset($filters['offset']) ? max(0, (int)$filters['offset']) : 0;
            $sql .= " LIMIT {$limit} OFFSET {$offset}";
        }

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map([$this, 'formatSealRow'], $rows);
    }

    public function getVerificationLogs(int $sealId, int $limit = 50): array
    {
        $tenantId = $this->getTenantId();
        $stmt = $this->db->pdo()->prepare("
            SELECT l.* 
            FROM document_verification_logs l
            JOIN document_seals s ON l.document_seal_id = s.id
            WHERE l.document_seal_id = ? AND s.tenant_id = ?
            ORDER BY l.verified_at DESC
            LIMIT ?
        ");
        $stmt->execute([$sealId, $tenantId, max(1, min(200, $limit))]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function verifyPayloadIntegrity(string $token, array $payload): bool
    {
        $stmt = $this->db->pdo()->prepare("SELECT canonical_hash FROM document_seals WHERE verification_token = ?");
        $stmt->execute([trim($token)]);
        $storedHash = $stmt->fetchColumn();

        if (!$storedHash) {
            return false;
        }

        $canonicalJson = $this->canonicalizePayload($payload);
        $calculatedHash = hash('sha256', $canonicalJson);

        return hash_equals((string)$storedHash, $calculatedHash);
    }

    // =========================================================================
    // HELPER FUNCTIONS
    // =========================================================================

    public function maskSubjectName(string $name): string
    {
        $name = trim($name);
        if (empty($name)) {
            return '***';
        }

        $words = preg_split('/\s+/', $name) ?: [];
        $maskedWords = [];

        foreach ($words as $w) {
            $len = mb_strlen($w);
            if ($len <= 2) {
                $maskedWords[] = mb_substr($w, 0, 1) . '*';
            } else {
                $prefixLen = min(2, (int)ceil($len / 3));
                $prefix = mb_substr($w, 0, $prefixLen);
                $maskedWords[] = $prefix . str_repeat('*', max(2, $len - $prefixLen));
            }
        }

        return implode(' ', $maskedWords);
    }

    public function maskIdentifier(string $identifier): string
    {
        $identifier = trim($identifier);
        $len = mb_strlen($identifier);
        if ($len <= 4) {
            return str_repeat('*', $len);
        }

        $prefix = mb_substr($identifier, 0, min(6, (int)ceil($len / 2)));
        return $prefix . str_repeat('*', max(3, $len - mb_strlen($prefix)));
    }

    protected function canonicalizePayload(array $payload): string
    {
        $sortRecursive = function (&$arr) use (&$sortRecursive) {
            ksort($arr);
            foreach ($arr as &$value) {
                if (is_array($value)) {
                    $sortRecursive($value);
                }
            }
        };

        $copy = $payload;
        $sortRecursive($copy);

        return (string) json_encode($copy, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    protected function getTenantSignatureSecret(int $tenantId): string
    {
        $salt = (function_exists('env') ? (string)env('APP_KEY', 'aio-signature-salt-secret-default-key-2026') : 'aio-signature-salt-secret-default-key-2026');
        return hash_hmac('sha256', (string)$tenantId, $salt);
    }

    protected function formatSealRow(array $row): array
    {
        $baseUrl = 'http://localhost:8000';
        if (function_exists('config')) {
            $baseUrl = rtrim((string)config('app.url', 'http://localhost:8000'), '/');
        }

        $row['verification_url'] = "{$baseUrl}/verify/{$row['verification_token']}";

        if (!empty($row['payload_snapshot'])) {
            $decoded = json_decode((string)$row['payload_snapshot'], true);
            $row['payload_data'] = is_array($decoded) ? $decoded : [];
        } else {
            $row['payload_data'] = [];
        }

        if (!empty($row['metadata'])) {
            $decoded = json_decode((string)$row['metadata'], true);
            $row['metadata_data'] = is_array($decoded) ? $decoded : [];
        } else {
            $row['metadata_data'] = [];
        }

        return $row;
    }
}
