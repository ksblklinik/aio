<?php

declare(strict_types=1);

namespace Core\Document;

use Core\Media\Barcode;
use Core\Media\QrCode;

class DocumentBuilder
{
    private string $layout = 'a4'; // a4, thermal_58mm, thermal_80mm, receipt
    private ?string $title = null;
    private ?string $subtitle = null;
    private ?string $logoUrl = null;
    private ?string $orgName = null;
    private ?string $orgAddress = null;
    private ?string $orgContact = null;
    private ?string $watermark = null;
    private ?string $notes = null;

    /** @var array<string, string> */
    private array $metadata = [];

    /** @var array<int, array{name: string, qty: float|int, price: float, unit: ?string, description: ?string, total: float}> */
    private array $items = [];

    /** @var array<int, array{label: string, amount: float, is_grand_total: bool}> */
    private array $totals = [];

    /** @var array<int, array{role: string, name: string, date: ?string}> */
    private array $signatures = [];

    private ?string $qrData = null;
    private int $qrSize = 90;

    private ?string $barcodeData = null;
    private int $barcodeWidth = 220;
    private int $barcodeHeight = 45;

    public function __construct(string $layout = 'a4')
    {
        $this->layout = strtolower($layout);
    }

    public static function create(string $layout = 'a4'): self
    {
        return new self($layout);
    }

    public function layout(string $layout): self
    {
        $this->layout = strtolower($layout);
        return $this;
    }

    public function title(string $title, ?string $subtitle = null): self
    {
        $this->title = $title;
        $this->subtitle = $subtitle;
        return $this;
    }

    public function organization(string $name, ?string $address = null, ?string $contact = null, ?string $logoUrl = null): self
    {
        $this->orgName = $name;
        $this->orgAddress = $address;
        $this->orgContact = $contact;
        $this->logoUrl = $logoUrl;
        return $this;
    }

    public function meta(string $key, string $value): self
    {
        $this->metadata[$key] = $value;
        return $this;
    }

    public function item(string $name, float|int $qty = 1, float $price = 0.0, ?string $unit = null, ?string $description = null): self
    {
        $this->items[] = [
            'name' => $name,
            'qty' => $qty,
            'price' => $price,
            'unit' => $unit,
            'description' => $description,
            'total' => $qty * $price,
        ];
        return $this;
    }

    public function total(string $label, float $amount, bool $isGrandTotal = false): self
    {
        $this->totals[] = [
            'label' => $label,
            'amount' => $amount,
            'is_grand_total' => $isGrandTotal,
        ];
        return $this;
    }

    public function signature(string $role, string $name, ?string $date = null): self
    {
        $this->signatures[] = [
            'role' => $role,
            'name' => $name,
            'date' => $date,
        ];
        return $this;
    }

    public function notes(string $notes): self
    {
        $this->notes = $notes;
        return $this;
    }

    public function watermark(string $text): self
    {
        $this->watermark = $text;
        return $this;
    }

    public function withQr(string $data, int $size = 90): self
    {
        $this->qrData = $data;
        $this->qrSize = $size;
        return $this;
    }

    public function withBarcode(string $data, int $width = 220, int $height = 45): self
    {
        $this->barcodeData = $data;
        $this->barcodeWidth = $width;
        $this->barcodeHeight = $height;
        return $this;
    }

    /**
     * Render dokumen lengkap ke dalam format HTML siap cetak
     */
    public function render(): string
    {
        return match ($this->layout) {
            'thermal_58mm' => $this->renderThermal(58),
            'thermal_80mm' => $this->renderThermal(80),
            'receipt' => $this->renderReceiptSlip(),
            default => $this->renderA4(),
        };
    }

    // ==========================================
    // RENDER LAYOUT 1: A4 FORMAL / INVOICE / SK
    // ==========================================
    private function renderA4(): string
    {
        $titleHtml = htmlspecialchars($this->title ?? 'DOKUMEN RESMI');
        $subtitleHtml = $this->subtitle ? "<div class=\"doc-sub\">" . htmlspecialchars($this->subtitle) . "</div>" : "";
        $orgHtml = htmlspecialchars($this->orgName ?? 'ORGANISASI / PLATFORM AIO');

        // Render QR & Barcode SVG
        $qrSvg = $this->qrData ? QrCode::svg($this->qrData, $this->qrSize) : '';
        $barcodeSvg = $this->barcodeData ? Barcode::svg($this->barcodeData, $this->barcodeWidth, $this->barcodeHeight) : '';

        // Render Metadata Rows
        $metaRows = '';
        foreach ($this->metadata as $k => $v) {
            $metaRows .= "<tr><td class=\"meta-lbl\">" . htmlspecialchars($k) . "</td><td class=\"meta-sep\">:</td><td class=\"meta-val\">" . htmlspecialchars($v) . "</td></tr>";
        }

        // Render Items Table
        $itemRows = '';
        $no = 1;
        $hasItems = !empty($this->items);
        foreach ($this->items as $it) {
            $desc = $it['description'] ? "<div class=\"it-desc\">" . htmlspecialchars($it['description']) . "</div>" : "";
            $unitStr = $it['unit'] ? " " . htmlspecialchars($it['unit']) : "";
            $priceStr = number_format($it['price'], 0, ',', '.');
            $totalStr = number_format($it['total'], 0, ',', '.');
            $itemRows .= "<tr>
                <td class=\"text-center\">{$no}</td>
                <td><strong>" . htmlspecialchars($it['name']) . "</strong>{$desc}</td>
                <td class=\"text-center\">{$it['qty']}{$unitStr}</td>
                <td class=\"text-right\">Rp {$priceStr}</td>
                <td class=\"text-right font-semibold\">Rp {$totalStr}</td>
            </tr>";
            $no++;
        }

        // Render Totals
        $totalsRows = '';
        foreach ($this->totals as $tot) {
            $lblClass = $tot['is_grand_total'] ? "font-bold text-primary" : "";
            $valClass = $tot['is_grand_total'] ? "font-bold text-primary grand-total" : "font-semibold";
            $amtStr = number_format($tot['amount'], 0, ',', '.');
            $totalsRows .= "<tr>
                <td colspan=\"4\" class=\"text-right {$lblClass}\">" . htmlspecialchars($tot['label']) . "</td>
                <td class=\"text-right {$valClass}\">Rp {$amtStr}</td>
            </tr>";
        }

        // Render Signatures
        $signCols = '';
        foreach ($this->signatures as $sign) {
            $dateHtml = $sign['date'] ? "<div class=\"sign-date\">" . htmlspecialchars($sign['date']) . "</div>" : "";
            $signCols .= "<div class=\"sign-box\">
                {$dateHtml}
                <div class=\"sign-role\">" . htmlspecialchars($sign['role']) . "</div>
                <div class=\"sign-space\"></div>
                <div class=\"sign-name\"><strong>" . htmlspecialchars($sign['name']) . "</strong></div>
            </div>";
        }

        // Watermark CSS/DIV
        $wmDiv = $this->watermark ? "<div class=\"watermark\">" . htmlspecialchars($this->watermark) . "</div>" : "";

        return <<<HTML
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>{$titleHtml}</title>
<style>
@page { size: A4; margin: 15mm 20mm 20mm 20mm; }
* { box-sizing: border-box; }
body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; font-size: 13px; line-height: 1.5; color: #1f2937; margin: 0; background: #fff; }
.doc-page { position: relative; max-width: 210mm; min-height: 297mm; margin: 0 auto; padding: 20px; }
.watermark { position: absolute; top: 38%; left: 10%; transform: rotate(-35deg); font-size: 80px; font-weight: 800; color: rgba(220, 38, 38, 0.08); pointer-events: none; z-index: 0; text-transform: uppercase; }
.header-box { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #1f2937; padding-bottom: 15px; margin-bottom: 20px; }
.org-info h2 { margin: 0 0 4px 0; font-size: 20px; font-weight: 700; color: #111827; }
.org-address { font-size: 11px; color: #4b5563; max-width: 320px; }
.doc-title-box { text-align: right; }
.doc-title { font-size: 22px; font-weight: 800; color: #2563eb; text-transform: uppercase; margin: 0; }
.doc-sub { font-size: 12px; color: #6b7280; font-weight: 500; }
.meta-grid { display: flex; justify-content: space-between; margin-bottom: 20px; }
.meta-table { border-collapse: collapse; font-size: 12px; }
.meta-lbl { font-weight: 600; color: #4b5563; padding-right: 8px; white-space: nowrap; }
.meta-sep { padding-right: 8px; color: #9ca3af; }
.meta-val { color: #111827; font-weight: 500; }
.table-data { width: 100%; border-collapse: collapse; margin-bottom: 20px; font-size: 12px; }
.table-data th { background: #f3f4f6; color: #374151; font-weight: 600; border: 1px solid #e5e7eb; padding: 8px 10px; text-transform: uppercase; font-size: 11px; }
.table-data td { border: 1px solid #e5e7eb; padding: 8px 10px; vertical-align: top; }
.text-center { text-align: center; }
.text-right { text-align: right; }
.font-semibold { font-weight: 600; }
.font-bold { font-weight: 700; }
.text-primary { color: #2563eb; }
.grand-total { font-size: 14px; }
.it-desc { font-size: 11px; color: #6b7280; margin-top: 2px; }
.notes-box { font-size: 11px; color: #4b5563; background: #f9fafb; padding: 10px; border-radius: 4px; border-left: 3px solid #3b82f6; margin-bottom: 25px; }
.footer-row { display: flex; justify-content: space-between; align-items: flex-end; margin-top: 30px; page-break-inside: avoid; }
.qr-barcode-box { display: flex; align-items: center; gap: 15px; }
.signatures-grid { display: flex; gap: 40px; justify-content: flex-end; }
.sign-box { text-align: center; min-width: 130px; }
.sign-role { font-weight: 600; font-size: 12px; color: #374151; margin-bottom: 8px; }
.sign-space { height: 60px; }
.sign-name { border-top: 1px solid #111827; padding-top: 4px; font-size: 12px; }
@media print {
  body { background: transparent; }
  .doc-page { padding: 0; }
  .no-print { display: none; }
}
</style>
</head>
<body>
<div class="doc-page">
  {$wmDiv}
  <div class="header-box">
    <div class="org-info">
      <h2>{$orgHtml}</h2>
      <div class="org-address">{$this->orgAddress} {$this->orgContact}</div>
    </div>
    <div class="doc-title-box">
      <div class="doc-title">{$titleHtml}</div>
      {$subtitleHtml}
    </div>
  </div>

  <div class="meta-grid">
    <table class="meta-table">{$metaRows}</table>
    <div class="meta-barcode">{$barcodeSvg}</div>
  </div>

  <table class="table-data">
    <thead>
      <tr>
        <th style="width: 40px;">No</th>
        <th>Deskripsi / Item</th>
        <th style="width: 70px;">Jumlah</th>
        <th style="width: 120px;">Harga Satuan</th>
        <th style="width: 130px;">Total</th>
      </tr>
    </thead>
    <tbody>
      {$itemRows}
      {$totalsRows}
    </tbody>
  </table>

  {$this->renderNotesBox()}

  <div class="footer-row">
    <div class="qr-barcode-box">
      {$qrSvg}
      <div style="font-size: 10px; color: #6b7280; max-width: 160px;">Dokumen ini diterbitkan secara sah dan terlindungi integritasnya.</div>
    </div>
    <div class="signatures-grid">
      {$signCols}
    </div>
  </div>
</div>
</body>
</html>
HTML;
    }

    // ==========================================
    // RENDER LAYOUT 2: THERMAL RECEIPT (58mm/80mm)
    // ==========================================
    private function renderThermal(int $widthMm): string
    {
        $titleHtml = htmlspecialchars($this->title ?? 'STRUK PEMBAYARAN');
        $orgHtml = htmlspecialchars($this->orgName ?? 'AIO POINT OF SALES');

        $qrSvg = $this->qrData ? QrCode::svg($this->qrData, min(140, $widthMm * 2)) : '';
        $barcodeSvg = $this->barcodeData ? Barcode::svg($this->barcodeData, min(200, $widthMm * 3), 35) : '';

        $metaLines = '';
        foreach ($this->metadata as $k => $v) {
            $metaLines .= "<tr><td>" . htmlspecialchars($k) . "</td><td class=\"text-right\">" . htmlspecialchars($v) . "</td></tr>";
        }

        $itemLines = '';
        foreach ($this->items as $it) {
            $tot = number_format($it['total'], 0, ',', '.');
            $prc = number_format($it['price'], 0, ',', '.');
            $itemLines .= "<tr>
                <td colspan=\"2\"><strong>" . htmlspecialchars($it['name']) . "</strong></td>
            </tr>
            <tr>
                <td class=\"item-qty\">{$it['qty']} x {$prc}</td>
                <td class=\"text-right font-bold\">{$tot}</td>
            </tr>";
        }

        $totalLines = '';
        foreach ($this->totals as $tot) {
            $amt = number_format($tot['amount'], 0, ',', '.');
            $cls = $tot['is_grand_total'] ? "grand-total font-bold" : "";
            $totalLines .= "<tr class=\"{$cls}\">
                <td>" . htmlspecialchars($tot['label']) . "</td>
                <td class=\"text-right\">Rp {$amt}</td>
            </tr>";
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>{$titleHtml}</title>
<style>
@page { size: {$widthMm}mm auto; margin: 0; }
body { font-family: "Courier New", Courier, monospace; font-size: 11px; width: {$widthMm}mm; margin: 0 auto; padding: 6px; color: #000; background: #fff; }
.header { text-align: center; margin-bottom: 8px; }
.header h3 { margin: 0 0 2px 0; font-size: 14px; text-transform: uppercase; }
.divider { border-top: 1px dashed #000; margin: 6px 0; }
table { width: 100%; border-collapse: collapse; font-size: 11px; }
td { padding: 1px 0; }
.text-right { text-align: right; }
.text-center { text-align: center; }
.font-bold { font-weight: bold; }
.item-qty { font-size: 10px; color: #333; }
.grand-total { font-size: 13px; border-top: 1px dashed #000; }
.qr-center { display: flex; justify-content: center; margin: 8px 0; }
.barcode-center { display: flex; justify-content: center; margin: 6px 0; }
.footer { text-align: center; font-size: 10px; margin-top: 8px; }
</style>
</head>
<body>
  <div class="header">
    <h3>{$orgHtml}</h3>
    <div>{$this->orgAddress}</div>
    <div class="divider"></div>
    <strong>{$titleHtml}</strong>
  </div>

  <table>{$metaLines}</table>
  <div class="divider"></div>

  <table>{$itemLines}</table>
  <div class="divider"></div>

  <table>{$totalLines}</table>
  <div class="divider"></div>

  <div class="qr-center">{$qrSvg}</div>
  <div class="barcode-center">{$barcodeSvg}</div>

  <div class="footer">
    {$this->notes}
    <div>*** TERIMA KASIH ***</div>
  </div>
</body>
</html>
HTML;
    }

    // ==========================================
    // RENDER LAYOUT 3: RECEIPT SLIP / KUITANSI
    // ==========================================
    private function renderReceiptSlip(): string
    {
        $titleHtml = htmlspecialchars($this->title ?? 'KUITANSI PEMBAYARAN');
        $orgHtml = htmlspecialchars($this->orgName ?? 'AIO FOUNDATION / ENTERPRISE');
        $qrSvg = $this->qrData ? QrCode::svg($this->qrData, 80) : '';

        $grandTotal = 0.0;
        foreach ($this->totals as $tot) {
            if ($tot['is_grand_total']) {
                $grandTotal = $tot['amount'];
                break;
            }
        }
        if ($grandTotal === 0.0 && !empty($this->totals)) {
            $grandTotal = end($this->totals)['amount'];
        }

        $metaRows = '';
        foreach ($this->metadata as $k => $v) {
            $metaRows .= "<tr><td class=\"lbl\">" . htmlspecialchars($k) . "</td><td>:</td><td class=\"val\">" . htmlspecialchars($v) . "</td></tr>";
        }

        $amtFormatted = number_format($grandTotal, 0, ',', '.');

        return <<<HTML
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>{$titleHtml}</title>
<style>
@page { size: 210mm 99mm; margin: 10mm; }
body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; font-size: 12px; margin: 0; background: #fff; }
.slip-box { border: 2px solid #2563eb; border-radius: 6px; padding: 15px; position: relative; }
.slip-header { display: flex; justify-content: space-between; border-bottom: 2px solid #2563eb; padding-bottom: 8px; margin-bottom: 12px; }
.slip-title { font-size: 18px; font-weight: 800; color: #2563eb; text-transform: uppercase; }
.meta-table { width: 100%; border-collapse: collapse; margin-bottom: 15px; }
.lbl { width: 140px; font-weight: 600; color: #4b5563; }
.val { font-weight: 500; }
.amount-badge { background: #eff6ff; border: 1px solid #bfdbfe; padding: 10px 15px; border-radius: 4px; display: inline-block; font-size: 16px; font-weight: 800; color: #1e40af; }
.slip-footer { display: flex; justify-content: space-between; align-items: flex-end; margin-top: 15px; }
</style>
</head>
<body>
<div class="slip-box">
  <div class="slip-header">
    <div>
      <div style="font-weight: 700; font-size: 14px;">{$orgHtml}</div>
      <div style="font-size: 10px; color: #6b7280;">{$this->orgAddress}</div>
    </div>
    <div class="slip-title">{$titleHtml}</div>
  </div>

  <table class="meta-table">{$metaRows}</table>

  <div class="slip-footer">
    <div>
      <div class="amount-badge">Rp {$amtFormatted}</div>
      <div style="font-size: 10px; color: #6b7280; margin-top: 4px;">{$this->notes}</div>
    </div>
    <div>{$qrSvg}</div>
  </div>
</div>
</body>
</html>
HTML;
    }

    private function renderNotesBox(): string
    {
        if (!$this->notes) {
            return '';
        }
        return "<div class=\"notes-box\"><strong>Catatan:</strong> " . htmlspecialchars($this->notes) . "</div>";
    }
}
