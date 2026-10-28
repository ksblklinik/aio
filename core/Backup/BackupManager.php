<?php

declare(strict_types=1);

namespace Core\Backup;

use Core\Backup\Drivers\BackupDriverInterface;
use Core\Backup\Integrity\DatabaseIntegrityChecker;
use Core\Backup\Integrity\IntegrityReport;
use Core\Backup\SelfHealing\DatabaseSelfHealer;
use Core\Database\Connection;
use RuntimeException;
use Throwable;

class BackupManager implements BackupManagerInterface
{
    private string $backupDirectory;
    private ?string $encryptionKey;

    public function __construct(
        private Connection $db,
        private BackupDriverInterface $driver,
        ?string $backupDirectory = null,
        ?string $encryptionKey = null
    ) {
        $this->backupDirectory = $backupDirectory
            ?? (dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'backups');
        $this->encryptionKey = $encryptionKey ?? ($_ENV['APP_KEY'] ?? null);

        if (!is_dir($this->backupDirectory)) {
            mkdir($this->backupDirectory, 0755, true);
        }
    }

    public function backup(array $options = []): BackupPackage
    {
        $compress = (bool) ($options['compress'] ?? true);
        $encrypt = (bool) ($options['encrypt'] ?? false);
        $tag = !empty($options['tag']) ? preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $options['tag']) : null;
        $customKey = (string) ($options['key'] ?? $this->encryptionKey ?? 'default_secret_key');

        $backupId = 'bkp_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3));
        $baseName = 'backup_' . date('Y-m-d_His') . ($tag ? '_' . $tag : '') . '_' . substr($backupId, -6);

        // 1. Create raw uncompressed snapshot via driver
        $rawSnapshotPath = $this->backupDirectory . DIRECTORY_SEPARATOR . $baseName . '.raw.sqlite';
        $this->driver->createSnapshot($rawSnapshotPath);

        $rawSha256 = hash_file('sha256', $rawSnapshotPath);
        $rawBytes = filesize($rawSnapshotPath);

        // 2. Read contents for pipeline processing
        $payload = file_get_contents($rawSnapshotPath);
        if ($payload === false) {
            @unlink($rawSnapshotPath);
            throw new RuntimeException("Gagal membaca berkas snapshot mentah: {$rawSnapshotPath}");
        }

        // 3. Optional Compression
        $ext = '.aio';
        if ($compress) {
            $payload = gzencode($payload, 9);
            $ext .= '.gz';
        }

        // 4. Optional AES-256-GCM Encryption
        $cipher = 'aes-256-gcm';
        if ($encrypt) {
            $key = hash('sha256', $customKey, true);
            $iv = random_bytes(12);
            $tagGcm = '';
            $encrypted = openssl_encrypt($payload, $cipher, $key, OPENSSL_RAW_DATA, $iv, $tagGcm);
            if ($encrypted === false) {
                @unlink($rawSnapshotPath);
                throw new RuntimeException("Gagal mengenkripsi paket backup dengan AES-256-GCM.");
            }
            // Prepend IV (12 bytes) and Tag (16 bytes)
            $payload = $iv . $tagGcm . $encrypted;
            $ext .= '.enc';
        }

        // 5. Write final package to disk and remove intermediate raw file
        $packagePath = $this->backupDirectory . DIRECTORY_SEPARATOR . $baseName . $ext;
        file_put_contents($packagePath, $payload);
        @unlink($rawSnapshotPath);

        $packageSize = filesize($packagePath);
        $packageSha256 = hash_file('sha256', $packagePath);

        // 6. Gather migration and table statistics for manifest
        $lastMigration = $this->getLastAppliedMigration();
        $tableCounts = $this->getTableCounts();

        $manifestData = [
            'backup_id' => $backupId,
            'base_name' => $baseName,
            'created_at' => date('c'),
            'database_type' => $this->driver->getDriverName(),
            'compressed' => $compress,
            'encrypted' => $encrypt,
            'cipher' => $encrypt ? $cipher : null,
            'last_migration' => $lastMigration,
            'raw_size_bytes' => $rawBytes,
            'package_size_bytes' => $packageSize,
            'sha256_unencrypted' => $rawSha256,
            'sha256_package' => $packageSha256,
            'table_counts' => $tableCounts,
            'tag' => $tag,
        ];

        // 7. Write manifest JSON
        $manifestPath = $this->backupDirectory . DIRECTORY_SEPARATOR . $baseName . '.manifest.json';
        file_put_contents(
            $manifestPath,
            json_encode($manifestData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        return new BackupPackage(
            backupId: $backupId,
            filePath: $packagePath,
            manifestPath: $manifestPath,
            sizeBytes: $packageSize,
            sha256: (string) $packageSha256,
            isEncrypted: $encrypt,
            isCompressed: $compress,
            manifestData: $manifestData,
            createdAt: date('c')
        );
    }

    public function restore(string $backupPath, bool $force = false): bool
    {
        $packagePath = $this->resolvePackagePath($backupPath);
        if (!file_exists($packagePath)) {
            throw new RuntimeException("Berkas paket cadangan tidak ditemukan: {$packagePath}");
        }

        // Find manifest
        $manifestPath = $this->resolveManifestPath($packagePath);
        $manifestData = file_exists($manifestPath)
            ? json_decode((string) file_get_contents($manifestPath), true)
            : [];

        $payload = file_get_contents($packagePath);
        if ($payload === false) {
            throw new RuntimeException("Gagal membaca berkas paket cadangan: {$packagePath}");
        }

        $isEncrypted = str_ends_with($packagePath, '.enc') || (!empty($manifestData['encrypted']));
        $isCompressed = str_contains($packagePath, '.gz') || (!empty($manifestData['compressed']));

        // 1. Decrypt if needed
        if ($isEncrypted) {
            $keyStr = $this->encryptionKey ?? 'default_secret_key';
            $key = hash('sha256', $keyStr, true);

            if (strlen($payload) < 28) { // 12 bytes IV + 16 bytes Tag
                throw new RuntimeException("Format berkas terenkripsi tidak valid atau rusak.");
            }

            $iv = substr($payload, 0, 12);
            $tagGcm = substr($payload, 12, 16);
            $ciphertext = substr($payload, 28);

            $decrypted = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tagGcm);
            if ($decrypted === false) {
                throw new RuntimeException("Gagal mendekripsi cadangan: Kunci otentikasi salah atau berkas telah dimanipulasi.");
            }

            $payload = $decrypted;
        }

        // 2. Decompress if needed
        if ($isCompressed) {
            $decompressed = @gzdecode($payload);
            if ($decompressed === false) {
                throw new RuntimeException("Gagal mendekompresi arsip cadangan (gzip corrupt).");
            }
            $payload = $decompressed;
        }

        // 3. Write unpacked snapshot to temp file
        $tempSnapshot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aio_restore_' . uniqid() . '.sqlite';
        file_put_contents($tempSnapshot, $payload);

        // 4. Verify unencrypted SHA-256 against manifest
        if (!empty($manifestData['sha256_unencrypted'])) {
            $actualRawHash = hash_file('sha256', $tempSnapshot);
            if (!hash_equals($manifestData['sha256_unencrypted'], (string) $actualRawHash)) {
                @unlink($tempSnapshot);
                if (!$force) {
                    throw new RuntimeException("Checksum integritas tidak cocok (bit rot terdeteksi). Pemulihan dibatalkan.");
                }
            }
        }

        // 5. Restore via driver
        try {
            $result = $this->driver->restoreSnapshot($tempSnapshot);
            @unlink($tempSnapshot);
            return $result;
        } catch (Throwable $e) {
            @unlink($tempSnapshot);
            throw $e;
        }
    }

    public function verify(): IntegrityReport
    {
        $checker = new DatabaseIntegrityChecker($this->db, $this->driver->getDatabasePath());
        return $checker->check();
    }

    public function heal(): array
    {
        $healer = new DatabaseSelfHealer($this->db, $this->driver->getDatabasePath());
        return $healer->heal();
    }

    public function listBackups(): array
    {
        $files = glob($this->backupDirectory . DIRECTORY_SEPARATOR . '*.manifest.json');
        if (!$files) {
            return [];
        }

        $list = [];
        foreach ($files as $mPath) {
            try {
                $raw = file_get_contents($mPath);
                $data = json_decode((string) $raw, true);
                if (is_array($data)) {
                    $baseName = $data['base_name'] ?? pathinfo($mPath, PATHINFO_FILENAME);
                    // Find matching package
                    $packages = glob($this->backupDirectory . DIRECTORY_SEPARATOR . $baseName . '.aio*');
                    $packageFile = !empty($packages) ? $packages[0] : null;

                    $list[] = [
                        'backup_id' => $data['backup_id'] ?? 'unknown',
                        'base_name' => $baseName,
                        'manifest_file' => basename($mPath),
                        'package_file' => $packageFile ? basename($packageFile) : null,
                        'package_path' => $packageFile,
                        'size_bytes' => $packageFile && file_exists($packageFile) ? filesize($packageFile) : ($data['package_size_bytes'] ?? 0),
                        'created_at' => $data['created_at'] ?? date('c', filemtime($mPath)),
                        'compressed' => (bool) ($data['compressed'] ?? false),
                        'encrypted' => (bool) ($data['encrypted'] ?? false),
                        'tag' => $data['tag'] ?? null,
                        'sha256' => $data['sha256_package'] ?? null,
                    ];
                }
            } catch (Throwable) {}
        }

        usort($list, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
        return $list;
    }

    public function prune(int $keepCount = 10): int
    {
        $all = $this->listBackups();
        if (count($all) <= $keepCount) {
            return 0;
        }

        $toDelete = array_slice($all, $keepCount);
        $deleted = 0;

        foreach ($toDelete as $item) {
            if (!empty($item['package_path']) && file_exists($item['package_path'])) {
                @unlink($item['package_path']);
            }
            $manifestPath = $this->backupDirectory . DIRECTORY_SEPARATOR . $item['manifest_file'];
            if (file_exists($manifestPath)) {
                @unlink($manifestPath);
            }
            $deleted++;
        }

        return $deleted;
    }

    public function getBackupDirectory(): string
    {
        return $this->backupDirectory;
    }

    private function resolvePackagePath(string $backupPath): string
    {
        if (file_exists($backupPath)) {
            return $backupPath;
        }

        $directInDir = $this->backupDirectory . DIRECTORY_SEPARATOR . basename($backupPath);
        if (file_exists($directInDir)) {
            return $directInDir;
        }

        // Try globbing
        $matches = glob($this->backupDirectory . DIRECTORY_SEPARATOR . basename($backupPath) . '*');
        if (!empty($matches)) {
            foreach ($matches as $m) {
                if (!str_ends_with($m, '.manifest.json')) {
                    return $m;
                }
            }
        }

        return $backupPath;
    }

    private function resolveManifestPath(string $packagePath): string
    {
        $info = pathinfo($packagePath);
        $filename = $info['filename'];
        // Strip .aio, .gz, .enc suffixes to get base name
        $baseName = preg_replace('/\.aio(\.gz)?(\.enc)?$/', '', basename($packagePath));

        $manifestDirect = dirname($packagePath) . DIRECTORY_SEPARATOR . $baseName . '.manifest.json';
        if (file_exists($manifestDirect)) {
            return $manifestDirect;
        }

        return dirname($packagePath) . DIRECTORY_SEPARATOR . $filename . '.manifest.json';
    }

    private function getLastAppliedMigration(): ?string
    {
        try {
            $row = $this->db->selectOne("SELECT migration FROM migrations ORDER BY id DESC LIMIT 1");
            return $row['migration'] ?? null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, int>
     */
    private function getTableCounts(): array
    {
        $counts = [];
        $keyTables = ['tenants', 'users', 'contacts', 'finance_accounts', 'items', 'billing_invoices', 'queue_tickets', 'locks'];

        foreach ($keyTables as $table) {
            try {
                $row = $this->db->selectOne("SELECT COUNT(*) AS total FROM \"{$table}\"");
                $counts[$table] = (int) ($row['total'] ?? 0);
            } catch (Throwable) {
                // Table might not exist yet
            }
        }

        return $counts;
    }
}
