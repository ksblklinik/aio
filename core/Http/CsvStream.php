<?php

declare(strict_types=1);

namespace Core\Http;

use Generator;

class CsvStream
{
    /**
     * Download data dalam format CSV secara streaming langsung ke browser
     *
     * @param string $filename Contoh: 'laporan-keuangan.csv'
     * @param array<string> $headers Header kolom CSV
     * @param iterable<array<int|string, mixed>> $rows Generator atau array baris data
     */
    public static function download(string $filename, array $headers, iterable $rows): Response
    {
        $cleanFilename = str_replace(['"', "\r", "\n"], '', $filename);
        if (!str_ends_with(strtolower($cleanFilename), '.csv')) {
            $cleanFilename .= '.csv';
        }

        $httpHeaders = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$cleanFilename}\"",
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ];

        return Response::stream(function () use ($headers, $rows) {
            $output = fopen('php://output', 'w');
            if ($output === false) {
                return;
            }

            // UTF-8 BOM untuk kompatibilitas Microsoft Excel
            fputs($output, "\xEF\xBB\xBF");

            if (!empty($headers)) {
                fputcsv($output, $headers);
            }

            foreach ($rows as $row) {
                if (is_array($row)) {
                    fputcsv($output, array_values($row));
                }
            }

            fclose($output);
        }, 200, $httpHeaders);
    }

    /**
     * Parsing file CSV secara streaming (memory-safe) baris demi baris
     *
     * @param string|resource $source Path file atau open stream resource
     * @param bool $hasHeader Apakah baris pertama adalah header
     * @return Generator<int, array<string|int, string>>
     */
    public static function parse(mixed $source, bool $hasHeader = true): Generator
    {
        $handle = is_resource($source) ? $source : @fopen((string) $source, 'r');
        if (!is_resource($handle)) {
            return;
        }

        $headers = [];
        $index = 0;

        try {
            // Hilangkan UTF-8 BOM jika ada
            $bom = fread($handle, 3);
            if ($bom !== "\xEF\xBB\xBF") {
                rewind($handle);
            }

            while (($row = fgetcsv($handle)) !== false) {
                // Lewati baris kosong
                if ($row === [null] || empty($row)) {
                    continue;
                }

                if ($hasHeader && empty($headers)) {
                    $headers = array_map('trim', $row);
                    continue;
                }

                if ($hasHeader && !empty($headers)) {
                    $combined = [];
                    foreach ($headers as $i => $header) {
                        $combined[$header] = $row[$i] ?? '';
                    }
                    yield $index++ => $combined;
                } else {
                    yield $index++ => $row;
                }
            }
        } finally {
            if (!is_resource($source) && is_resource($handle)) {
                fclose($handle);
            }
        }
    }
}
