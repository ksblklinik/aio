<?php

declare(strict_types=1);

namespace Core\Export;

class CsvExporter
{
    private string $delimiter = ',';
    private string $enclosure = '"';
    private string $escape = "\\";
    private bool $includeBom = true;

    public function setDelimiter(string $delimiter): self
    {
        $this->delimiter = $delimiter;
        return $this;
    }

    public function setEnclosure(string $enclosure): self
    {
        $this->enclosure = $enclosure;
        return $this;
    }

    public function withBom(bool $includeBom): self
    {
        $this->includeBom = $includeBom;
        return $this;
    }

    /**
     * Tulis streaming CSV ke stream yang diberikan (default: php://output)
     *
     * @param iterable<int, array<string|int, mixed>> $records
     * @param array<int, string> $headers
     * @param resource|null $stream
     * @return int Jumlah baris data yang diekspor
     */
    public function stream(iterable $records, array $headers = [], $stream = null): int
    {
        $handle = $stream ?? fopen('php://output', 'w');
        if (!is_resource($handle)) {
            throw new \RuntimeException("Unable to open output stream for CSV export.");
        }

        // Sertakan UTF-8 BOM untuk kompatibilitas sempurna di Microsoft Excel
        if ($this->includeBom) {
            fwrite($handle, "\xEF\xBB\xBF");
        }

        // Tulis baris header jika ada
        if (!empty($headers)) {
            fputcsv($handle, $headers, $this->delimiter, $this->enclosure, $this->escape);
        }

        $rowCount = 0;
        foreach ($records as $record) {
            $row = is_array($record) ? array_values($record) : [(string) $record];
            fputcsv($handle, $row, $this->delimiter, $this->enclosure, $this->escape);
            $rowCount++;
        }

        if ($stream === null) {
            fclose($handle);
        }

        return $rowCount;
    }

    /**
     * Dapatkan representasi string CSV lengkap (menggunakan memori sementara stream)
     *
     * @param iterable<int, array<string|int, mixed>> $records
     * @param array<int, string> $headers
     */
    public function toString(iterable $records, array $headers = []): string
    {
        $handle = fopen('php://temp', 'r+');
        if (!is_resource($handle)) {
            throw new \RuntimeException("Unable to open php://temp stream.");
        }

        $this->stream($records, $headers, $handle);

        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        return $content !== false ? $content : '';
    }

    /**
     * Simpan data CSV langsung ke file target di disk
     *
     * @param iterable<int, array<string|int, mixed>> $records
     * @param array<int, string> $headers
     */
    public function toFile(iterable $records, array $headers, string $filePath): int
    {
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $handle = fopen($filePath, 'w');
        if (!is_resource($handle)) {
            throw new \RuntimeException("Unable to open file '{$filePath}' for writing CSV.");
        }

        $count = $this->stream($records, $headers, $handle);
        fclose($handle);

        return $count;
    }

    /**
     * Header HTTP download attachment untuk file CSV
     *
     * @return array<string, string>
     */
    public function downloadHeaders(string $filename): array
    {
        $cleanFilename = basename($filename);
        if (!str_ends_with(strtolower($cleanFilename), '.csv')) {
            $cleanFilename .= '.csv';
        }

        return [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$cleanFilename}\"",
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ];
    }
}
