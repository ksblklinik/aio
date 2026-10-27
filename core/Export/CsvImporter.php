<?php

declare(strict_types=1);

namespace Core\Export;

use Core\Validation\Validator;
use PDO;
use RuntimeException;

class CsvImporter
{
    private string $delimiter = ',';
    private string $enclosure = '"';
    private string $escape = "\\";
    private ?PDO $pdo = null;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo;
    }

    public function setDelimiter(string $delimiter): self
    {
        $this->delimiter = $delimiter;
        return $this;
    }

    /**
     * Parse file CSV secara streaming per-baris (hemat memori) dengan validasi otomatis
     *
     * @param string $filePath Path file CSV
     * @param callable $rowCallback Fungsi callback dieksekusi untuk setiap baris valid: fn(array $row, int $lineNum): void
     * @param array<string, string|array<int, string>> $rules Aturan validasi opsional untuk setiap baris
     * @param bool $hasHeader Apakah baris pertama adalah nama kolom/header
     * @return array{total: int, imported: int, failed: int, errors: array<int, array<string, string>>}
     */
    public function parse(
        string $filePath,
        callable $rowCallback,
        array $rules = [],
        bool $hasHeader = true
    ): array {
        if (!file_exists($filePath)) {
            throw new RuntimeException("File CSV tidak ditemukan: {$filePath}");
        }

        $handle = fopen($filePath, 'r');
        if (!is_resource($handle)) {
            throw new RuntimeException("Gagal membuka stream file CSV: {$filePath}");
        }

        $headers = [];
        $lineNum = 0;
        $total = 0;
        $imported = 0;
        $failed = 0;
        $errors = [];

        while (($rawRow = fgetcsv($handle, 0, $this->delimiter, $this->enclosure, $this->escape)) !== false) {
            $lineNum++;

            // Abaikan baris kosong
            if ($rawRow === [null] || empty(array_filter($rawRow, fn($v) => trim((string) $v) !== ''))) {
                continue;
            }

            // Hapus UTF-8 BOM pada baris pertama kolom pertama jika ada
            if ($lineNum === 1 && isset($rawRow[0])) {
                $rawRow[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $rawRow[0]);
            }

            // Tangani header
            if ($hasHeader && empty($headers)) {
                $headers = array_map(fn($h) => trim((string) $h), $rawRow);
                continue;
            }

            $total++;

            // Bentuk associative array jika ada header
            $rowData = [];
            if (!empty($headers)) {
                foreach ($headers as $index => $colName) {
                    $rowData[$colName] = $rawRow[$index] ?? null;
                }
            } else {
                $rowData = $rawRow;
            }

            // Validasi baris jika rules diberikan
            if (!empty($rules)) {
                $validator = Validator::make($rowData, $rules, $this->pdo);
                if ($validator->fails()) {
                    $failed++;
                    $errors[$lineNum] = $validator->errors();
                    continue;
                }
                $rowData = $validator->validated();
            }

            // Eksekusi callback baris
            try {
                $rowCallback($rowData, $lineNum);
                $imported++;
            } catch (\Throwable $e) {
                $failed++;
                $errors[$lineNum] = ['_exception' => $e->getMessage()];
            }
        }

        fclose($handle);

        return [
            'total' => $total,
            'imported' => $imported,
            'failed' => $failed,
            'errors' => $errors,
        ];
    }
}
