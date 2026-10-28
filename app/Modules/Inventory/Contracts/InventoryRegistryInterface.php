<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Contracts;

interface InventoryRegistryInterface
{
    /**
     * Buat master item baru (barang atau jasa).
     *
     * @param array<string, mixed> $data
     */
    public function createItem(array $data): int;

    /**
     * Perbarui data master item.
     *
     * @param array<string, mixed> $data
     */
    public function updateItem(int $itemId, array $data): bool;

    /**
     * Ambil data item berdasarkan ID.
     *
     * @return array<string, mixed>|null
     */
    public function getItem(int $itemId): ?array;

    /**
     * Ambil data item berdasarkan kode.
     *
     * @return array<string, mixed>|null
     */
    public function getItemByCode(string $code): ?array;

    /**
     * Ambil data item berdasarkan barcode / SKU.
     *
     * @return array<string, mixed>|null
     */
    public function getItemByBarcode(string $barcode): ?array;

    /**
     * Cari item berdasarkan kata kunci nama/kode/sku/barcode.
     *
     * @return array<int, array<string, mixed>>
     */
    public function searchItems(string $query = '', ?string $type = null, int $limit = 50): array;

    /**
     * Dapatkan sisa stok fisik saat ini dari sebuah item.
     */
    public function getStock(int $itemId): float;

    /**
     * Periksa apakah stok item mencukupi untuk kuantitas tertentu.
     * Untuk item bertipe 'service' atau yang tidak ditrack inventory-nya, selalu mengembalikan true.
     */
    public function checkAvailability(int $itemId, float $requiredQuantity): bool;

    /**
     * Catat mutasi pergerakan stok fisik (in, out, adjustment, opname).
     */
    public function recordStockMovement(
        int $itemId,
        float $quantity,
        string $type,
        string $notes = '',
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?float $unitCost = null,
        ?int $userId = null,
        ?int $roomId = null,
        ?int $batchId = null
    ): int;

    /**
     * Ambil daftar satuan ukuran terdaftar (auto-seed default jika kosong).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listUnits(): array;

    /**
     * Buat satuan ukuran baru.
     */
    public function createUnit(string $code, string $name): int;

    /**
     * Ambil daftar kategori item.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listCategories(): array;

    /**
     * Buat kategori item baru.
     */
    public function createCategory(string $code, string $name, ?int $parentId = null): int;

    /**
     * Dapatkan daftar item yang stoknya berada di bawah atau sama dengan batas minimum peringatan.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listLowStockItems(): array;

    /**
     * Dapatkan riwayat mutasi pergerakan stok dari sebuah item.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getStockMovementHistory(int $itemId, int $limit = 50): array;

    // ==========================================
    // SPEC-029B: Multi-Warehouse & Batch Methods
    // ==========================================

    /**
     * Dapatkan rincian stok item per ruangan/gudang.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getStockByLocation(int $itemId, ?int $roomId = null): array;

    /**
     * Dapatkan total stok item pada ruangan/gudang tertentu.
     */
    public function getLocationStock(int $itemId, int $roomId): float;

    /**
     * Buat nomor batch baru untuk item.
     */
    public function createBatch(
        int $itemId,
        string $batchNumber,
        string $expiryDate,
        float $quantity = 0.0,
        float $costPrice = 0.0,
        ?string $notes = null
    ): int;

    /**
     * Ambil rincian batch berdasarkan ID.
     *
     * @return array<string, mixed>|null
     */
    public function getBatch(int $batchId): ?array;

    /**
     * Ambil daftar batch terdaftar dari sebuah item.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listBatches(int $itemId, ?string $status = 'active'): array;

    /**
     * Ambil daftar batch barang yang mendekati tanggal kadaluarsa.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listNearExpiryItems(int $daysThreshold = 30): array;

    /**
     * Perbarui status batch (active, quarantined, expired).
     */
    public function updateBatchStatus(int $batchId, string $status): bool;

    /**
     * Potong stok menggunakan metode alokasi FEFO (First-Expired, First-Out).
     *
     * @return array<int, array<string, mixed>> Rincian alokasi per batch yang dipotong
     */
    public function deductStockFefo(
        int $itemId,
        float $quantity,
        ?int $roomId = null,
        string $notes = '',
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?int $userId = null
    ): array;

    /**
     * Transfer stok antar-ruangan/gudang secara atomik.
     *
     * @param array<int, array{item_id: int, quantity: float, batch_id?: int|null}> $items
     * @return array<string, mixed> Data transaksi transfer
     */
    public function transferStock(
        int $sourceRoomId,
        int $destinationRoomId,
        array $items,
        ?string $notes = null,
        ?int $userId = null
    ): array;

    /**
     * Ambil detail transfer berdasarkan ID.
     *
     * @return array<string, mixed>|null
     */
    public function getTransfer(int $transferId): ?array;

    /**
     * Ambil daftar riwayat transfer stok antar-gudang.
     *
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function listTransfers(array $filters = []): array;
}
