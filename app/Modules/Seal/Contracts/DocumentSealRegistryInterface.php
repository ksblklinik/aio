<?php

declare(strict_types=1);

namespace App\Modules\Seal\Contracts;

interface DocumentSealRegistryInterface
{
    /**
     * Menerbitkan dan menyegel dokumen secara kriptografis.
     * Menghasilkan verification_token, canonical_hash (SHA-256), signature (HMAC-SHA256), dan verification_url.
     */
    public function sealDocument(array $data): array;

    /**
     * Mengambil record segel berdasarkan ID primer (terisolasi per tenant aktif).
     */
    public function getSeal(int $id): ?array;

    /**
     * Mengambil record segel berdasarkan verification_token (akses internal tenant).
     */
    public function getSealByToken(string $token): ?array;

    /**
     * Verifikasi publik dokumen berdasarkan verification_token.
     * Dapat diakses secara terbuka tanpa autentikasi (Zero-Trust Public Portal).
     * Data sensitif diproteksi dengan penyamaran privasi (Privacy Masking).
     * Secara otomatis mencatat log akses ke tabel document_verification_logs.
     */
    public function verifyPublicDocument(string $token, array $requestMeta = []): array;

    /**
     * Mencabut status keabsahan segel dokumen (Revocation) disertai alasan resmi.
     */
    public function revokeSeal(string $token, string $reason): array;

    /**
     * Daftar segel dokumen dengan filter opsional (document_type, status, search, pagination).
     */
    public function listSeals(array $filters = []): array;

    /**
     * Mengambil riwayat log pemindaian/verifikasi untuk dokumen tertentu.
     */
    public function getVerificationLogs(int $sealId, int $limit = 50): array;

    /**
     * Verifikasi integritas payload data langsung terhadap canonical hash dokumen.
     */
    public function verifyPayloadIntegrity(string $token, array $payload): bool;
}
