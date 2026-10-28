<?php

declare(strict_types=1);

namespace Core\Backup;

class BackupPackage
{
    /**
     * @param array<string, mixed> $manifestData
     */
    public function __construct(
        public readonly string $backupId,
        public readonly string $filePath,
        public readonly string $manifestPath,
        public readonly int $sizeBytes,
        public readonly string $sha256,
        public readonly bool $isEncrypted,
        public readonly bool $isCompressed,
        public readonly array $manifestData,
        public readonly string $createdAt
    ) {
    }

    public function verifyChecksum(): bool
    {
        if (!file_exists($this->filePath)) {
            return false;
        }

        $actual = hash_file('sha256', $this->filePath);
        return hash_equals($this->sha256, (string) $actual);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'backup_id' => $this->backupId,
            'file_path' => $this->filePath,
            'file_name' => basename($this->filePath),
            'manifest_path' => $this->manifestPath,
            'size_bytes' => $this->sizeBytes,
            'formatted_size' => $this->formatBytes($this->sizeBytes),
            'sha256' => $this->sha256,
            'is_encrypted' => $this->isEncrypted,
            'is_compressed' => $this->isCompressed,
            'created_at' => $this->createdAt,
            'manifest' => $this->manifestData,
        ];
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return sprintf('%.2f MB', $bytes / 1048576);
        }
        if ($bytes >= 1024) {
            return sprintf('%.2f KB', $bytes / 1024);
        }
        return "{$bytes} B";
    }
}
