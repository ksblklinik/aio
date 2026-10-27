<?php

declare(strict_types=1);

namespace Core\Export;

use PDO;

class ExporterManager
{
    private ?CsvExporter $csv = null;
    private ?ExcelExporter $excel = null;

    public function __construct(private ?PDO $pdo = null) {}

    public function csv(): CsvExporter
    {
        if ($this->csv === null) {
            $this->csv = new CsvExporter();
        }
        return $this->csv;
    }

    public function excel(): ExcelExporter
    {
        if ($this->excel === null) {
            $this->excel = new ExcelExporter();
        }
        return $this->excel;
    }

    public function importer(?PDO $pdo = null): CsvImporter
    {
        return new CsvImporter($pdo ?? $this->pdo);
    }
}
