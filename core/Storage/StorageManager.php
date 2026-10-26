<?php

declare(strict_types=1);

namespace Core\Storage;

use Core\Database\Connection;
use Core\Database\TenantContext;
use Core\Http\UploadedFile;
use InvalidArgumentException;
use RuntimeException;

class StorageManager
{
    private StorageDriverInterface $driver;
    private ?Connection $db;
    private ?int $scopedTenantId = null;
    private string $appKey;

    public function __construct(
        StorageDriverInterface $driver,
        ?Connection $db = null,
        string $appKey = 'aio-universal-secret-key-32chars!!'
    ) {
        $this->driver = $driver;
        $this->db = $db;
        $this->appKey = $appKey;
    }

    /**
     * Set tenant scope eksplisit untuk operasi storage
     */
    public function forTenant(?int $tenantId): self
    {
        $clone = clone $this;
        $clone->scopedTenantId = $tenantId;
        return $clone;
    }

    public function getActiveTenantId(): int
    {
        if ($this->scopedTenantId !== null) {
            return $this->scopedTenantId;
        }

        $contextId = TenantContext::getInstance()->getTenantId();
        if ($contextId !== null && $contextId !== '') {
            return (int) $contextId;
        }

        return 0; // Default / global namespace jika di luar tenant
    }

    /**
     * Ubah relative path pengguna menjadi tenant-isolated path
     */
    public function resolveTenantPath(string $path): string
    {
        $tenantId = $this->getActiveTenantId();
        $cleanPath = ltrim(str_replace(['\\', "\0"], ['/', ''], $path), '/');
        return "tenants/{$tenantId}/{$cleanPath}";
    }

    public function put(string $path, string $contents): bool
    {
        return $this->driver->put($this->resolveTenantPath($path), $contents);
    }

    public function get(string $path): ?string
    {
        return $this->driver->get($this->resolveTenantPath($path));
    }

    public function exists(string $path): bool
    {
        return $this->driver->exists($this->resolveTenantPath($path));
    }

    public function delete(string $path): bool
    {
        return $this->driver->delete($this->resolveTenantPath($path));
    }

    public function size(string $path): int
    {
        return $this->driver->size($this->resolveTenantPath($path));
    }

    public function mimeType(string $path): ?string
    {
        return $this->driver->mimeType($this->resolveTenantPath($path));
    }

    public function path(string $path): string
    {
        return $this->driver->path($this->resolveTenantPath($path));
    }

    public function getDriver(): StorageDriverInterface
    {
        return $this->driver;
    }

    /**
     * Upload dan catat metadata file ke database secara terisolasi tenant
     *
     * @return array<string, mixed> Metadata record file yang disimpan
     */
    public function store(
        UploadedFile $file,
        string $directory = 'documents',
        bool $isPublic = false,
        ?string $attachableType = null,
        ?int $attachableId = null,
        ?int $userId = null
    ): array {
        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw new RuntimeException("Gagal mengunggah file: Kode error upload {$file->getError()}");
        }

        $tenantId = $this->getActiveTenantId();
        if ($tenantId <= 0) {
            throw new InvalidArgumentException("Operasi unggah file memerlukan konteks tenant yang aktif.");
        }

        $originalName = $file->getClientOriginalName();
        $extension = $file->getClientOriginalExtension();
        $randomName = bin2hex(random_bytes(16)) . ($extension !== '' ? ".{$extension}" : '');

        $cleanDir = trim(str_replace(['\\', '..'], ['/', ''], $directory), '/');
        $relativeFilePath = ($cleanDir !== '' ? "{$cleanDir}/" : "") . $randomName;
        $tenantScopedPath = "tenants/{$tenantId}/{$relativeFilePath}";

        $content = $file->getContent();
        if ($content === '' && $file->getSize() > 0) {
            $tempPath = $file->getTmpName();
            if (file_exists($tempPath)) {
                $content = (string) file_get_contents($tempPath);
            }
        }

        $fileSize = $file->getSize() ?: strlen($content);
        $checksum = hash('sha256', $content);
        $mimeType = $file->getClientMimeType() ?: 'application/octet-stream';

        // Tulis file fisik
        if (!$this->driver->put($tenantScopedPath, $content)) {
            throw new RuntimeException("Gagal menyimpan berkas ke media storage.");
        }

        // Catat metadata ke database files
        if ($this->db !== null) {
            $this->db->insert('files', [
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'disk' => 'local',
                'original_name' => $originalName,
                'file_name' => $randomName,
                'file_path' => $relativeFilePath,
                'mime_type' => $mimeType,
                'file_size' => $fileSize,
                'checksum_sha256' => $checksum,
                'is_public' => $isPublic ? 1 : 0,
                'attachable_type' => $attachableType,
                'attachable_id' => $attachableId,
            ]);

            $id = (int) $this->db->getPdo()->lastInsertId();
            return $this->getFile($id) ?? [];
        }

        return [
            'tenant_id' => $tenantId,
            'original_name' => $originalName,
            'file_name' => $randomName,
            'file_path' => $relativeFilePath,
            'mime_type' => $mimeType,
            'file_size' => $fileSize,
            'checksum_sha256' => $checksum,
            'is_public' => $isPublic,
        ];
    }

    /**
     * Ambil metadata file dari database berdasarkan ID
     */
    public function getFile(int $fileId): ?array
    {
        if ($this->db === null) {
            return null;
        }

        $tenantId = $this->getActiveTenantId();
        $query = "SELECT * FROM files WHERE id = ?";
        $params = [$fileId];

        // Isolasi tenant: hanya akses file milik tenant aktif (atau jika admin eksplisit)
        if ($tenantId > 0) {
            $query .= " AND tenant_id = ?";
            $params[] = $tenantId;
        }

        return $this->db->selectOne($query, $params);
    }

    /**
     * Hapus file fisik dan metadata record dari database
     */
    public function deleteFile(int $fileId): bool
    {
        $file = $this->getFile($fileId);
        if (!$file) {
            return false;
        }

        $tenantScopedPath = "tenants/{$file['tenant_id']}/{$file['file_path']}";
        $this->driver->delete($tenantScopedPath);

        if ($this->db !== null) {
            $this->db->delete('files', 'id = ?', [$fileId]);
        }

        return true;
    }

    /**
     * Buat URL unduhan sementara bertanda tangan kriptografis (Signed Temporary URL)
     */
    public function temporaryUrl(int $fileId, int $expirationSeconds = 300): string
    {
        $expires = time() + $expirationSeconds;
        $signature = hash_hmac('sha256', "download:{$fileId}:{$expires}", $this->appKey);

        return "/storage/download/{$fileId}?expires={$expires}&signature={$signature}";
    }

    /**
     * Validasi tanda tangan kriptografis dan TTL URL unduhan
     */
    public function validateSignature(int $fileId, int $expires, string $signature): bool
    {
        if ($expires < time()) {
            return false; // Kedaluwarsa
        }

        $expected = hash_hmac('sha256', "download:{$fileId}:{$expires}", $this->appKey);
        return hash_equals($expected, $signature);
    }
}
