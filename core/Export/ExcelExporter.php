<?php

declare(strict_types=1);

namespace Core\Export;

class ExcelExporter
{
    /**
     * Hasilkan XML SpreadsheetML murni kompatibel Excel dan LibreOffice Calc
     *
     * @param iterable<int, array<string|int, mixed>> $rows
     * @param array<int, string> $headers
     */
    public function generate(iterable $rows, array $headers = [], string $sheetTitle = 'Report'): string
    {
        $cleanTitle = htmlspecialchars(mb_substr($sheetTitle, 0, 31), ENT_XML1);

        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        $xml .= "<?mso-application progid=\"Excel.Sheet\"?>\n";
        $xml .= "<Workbook xmlns=\"urn:schemas-microsoft-com:office:spreadsheet\"\n";
        $xml .= " xmlns:o=\"urn:schemas-microsoft-com:office:office\"\n";
        $xml .= " xmlns:x=\"urn:schemas-microsoft-com:office:excel\"\n";
        $xml .= " xmlns:ss=\"urn:schemas-microsoft-com:office:spreadsheet\"\n";
        $xml .= " xmlns:html=\"http://www.w3.org/TR/REC-html40\">\n";

        // Styles: Header styling, numbers, formatting
        $xml .= " <Styles>\n";
        $xml .= "  <Style ss:ID=\"Default\" ss:Name=\"Normal\">\n";
        $xml .= "   <Alignment ss:Vertical=\"Bottom\"/>\n";
        $xml .= "   <Font ss:FontName=\"Segoe UI\" ss:Size=\"10\" ss:Color=\"#1F2937\"/>\n";
        $xml .= "  </Style>\n";
        $xml .= "  <Style ss:ID=\"Header\">\n";
        $xml .= "   <Alignment ss:Horizontal=\"Center\" ss:Vertical=\"Center\"/>\n";
        $xml .= "   <Font ss:FontName=\"Segoe UI\" ss:Size=\"10\" ss:Color=\"#FFFFFF\" ss:Bold=\"1\"/>\n";
        $xml .= "   <Interior ss:Color=\"#3B82F6\" ss:Pattern=\"Solid\"/>\n";
        $xml .= "  </Style>\n";
        $xml .= "  <Style ss:ID=\"Numeric\">\n";
        $xml .= "   <NumberFormat ss:Format=\"#,##0.00\"/>\n";
        $xml .= "  </Style>\n";
        $xml .= "  <Style ss:ID=\"Integer\">\n";
        $xml .= "   <NumberFormat ss:Format=\"#,##0\"/>\n";
        $xml .= "  </Style>\n";
        $xml .= " </Styles>\n";

        // Worksheet & Table
        $xml .= " <Worksheet ss:Name=\"{$cleanTitle}\">\n";
        $xml .= "  <Table>\n";

        // Render Headers
        if (!empty($headers)) {
            $xml .= "   <Row ss:Height=\"22\">\n";
            foreach ($headers as $header) {
                $escaped = htmlspecialchars((string) $header, ENT_XML1);
                $xml .= "    <Cell ss:StyleID=\"Header\"><Data ss:Type=\"String\">{$escaped}</Data></Cell>\n";
            }
            $xml .= "   </Row>\n";
        }

        // Render Data Rows
        foreach ($rows as $record) {
            $rowValues = is_array($record) ? array_values($record) : [(string) $record];
            $xml .= "   <Row ss:Height=\"18\">\n";
            foreach ($rowValues as $val) {
                if ($val === null) {
                    $xml .= "    <Cell><Data ss:Type=\"String\"></Data></Cell>\n";
                } elseif (is_int($val)) {
                    $xml .= "    <Cell ss:StyleID=\"Integer\"><Data ss:Type=\"Number\">{$val}</Data></Cell>\n";
                } elseif (is_float($val)) {
                    $xml .= "    <Cell ss:StyleID=\"Numeric\"><Data ss:Type=\"Number\">{$val}</Data></Cell>\n";
                } elseif (is_numeric($val) && !str_starts_with((string) $val, '0')) {
                    // Angka dalam bentuk string numerik (bukan format kode telepon/pos yang berawalan 0)
                    $xml .= "    <Cell ss:StyleID=\"Numeric\"><Data ss:Type=\"Number\">{$val}</Data></Cell>\n";
                } else {
                    $escaped = htmlspecialchars((string) $val, ENT_XML1);
                    $xml .= "    <Cell><Data ss:Type=\"String\">{$escaped}</Data></Cell>\n";
                }
            }
            $xml .= "   </Row>\n";
        }

        $xml .= "  </Table>\n";
        $xml .= " </Worksheet>\n";
        $xml .= "</Workbook>";

        return $xml;
    }

    /**
     * Simpan file Excel XML ke disk
     *
     * @param iterable<int, array<string|int, mixed>> $rows
     * @param array<int, string> $headers
     */
    public function toFile(iterable $rows, array $headers, string $filePath, string $sheetTitle = 'Report'): int
    {
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $content = $this->generate($rows, $headers, $sheetTitle);
        $res = file_put_contents($filePath, $content);
        if ($res === false) {
            throw new \RuntimeException("Unable to write Excel file to {$filePath}.");
        }

        return is_array($rows) ? count($rows) : iterator_count((function () use ($rows) { yield from $rows; })());
    }

    /**
     * Header HTTP download attachment untuk Excel Spreadsheet XML (.xls)
     *
     * @return array<string, string>
     */
    public function downloadHeaders(string $filename): array
    {
        $cleanFilename = basename($filename);
        if (!str_ends_with(strtolower($cleanFilename), '.xls') && !str_ends_with(strtolower($cleanFilename), '.xml')) {
            $cleanFilename .= '.xls';
        }

        return [
            'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$cleanFilename}\"",
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ];
    }
}
