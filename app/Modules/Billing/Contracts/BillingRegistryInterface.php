<?php

declare(strict_types=1);

namespace App\Modules\Billing\Contracts;

interface BillingRegistryInterface
{
    /**
     * Buat invoice atau bill baru (default status: draft atau issued).
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed> Data invoice yang berhasil dibuat beserta rincian items
     */
    public function createInvoice(array $data): array;

    /**
     * Dapatkan data invoice lengkap beserta items dan payments.
     *
     * @return array<string, mixed>|null
     */
    public function getInvoice(int $id): ?array;

    /**
     * Dapatkan invoice berdasarkan nomor invoice.
     *
     * @return array<string, mixed>|null
     */
    public function getInvoiceByNumber(string $invoiceNumber): ?array;

    /**
     * Perbarui data invoice draft (hanya berlaku jika status masih 'draft').
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function updateInvoice(int $id, array $data): array;

    /**
     * Terbitkan invoice (ubah status dari draft menjadi issued).
     * Jika $deductInventory bernilai true, stok barang fisik akan otomatis dipotong via inventory().
     *
     * @return array<string, mixed>
     */
    public function issueInvoice(int $id, bool $deductInventory = true): array;

    /**
     * Rekam pembayaran atas suatu invoice/bill.
     * Otomatis mengupdate amount_paid, amount_due, status pelunasan,
     * serta mencatat mutasi kas masuk/keluar ke akun keuangan via finance().
     *
     * @param array<string, mixed> $paymentData
     * @return array<string, mixed>
     */
    public function recordPayment(int $invoiceId, array $paymentData): array;

    /**
     * Transaksi Kasir Instan (Quick POS Checkout):
     * Seketika membuat invoice, memotong stok fisik via inventory(),
     * mencatat pembayaran lunas dan membukukan mutasi kas via finance() dalam satu transaksi atomik.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed> Invoice yang telah lunas beserta payment receipt
     */
    public function quickCheckout(array $data): array;

    /**
     * Batalkan invoice. Jika stok sebelumnya sudah dipotong, stok barang akan dikembalikan (revert stock).
     *
     * @return array<string, mixed>
     */
    public function cancelInvoice(int $id, ?string $reason = null, bool $revertStock = true): array;

    /**
     * Daftar seluruh invoice berdasarkan filter (status, payment_status, contact_id, date range, type).
     *
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function listInvoices(array $filters = [], int $limit = 50, int $offset = 0): array;

    /**
     * Dapatkan ringkasan statistik keuangan billing (Total Ditagihkan, Total Diterima, Sisa Piutang / Outstanding).
     *
     * @return array<string, float>
     */
    public function getBillingSummary(): array;

    /**
     * Generate data terstruktur struk / kuitansi termal untuk pencetakan dokumen.
     *
     * @return array<string, mixed>
     */
    public function generateReceipt(int $invoiceId): array;
}
