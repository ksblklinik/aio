<?php

namespace App\Modules\Tariff\Contracts;

interface TariffRegistryInterface
{
    /**
     * Create a new price book (e.g. Regular, BPJS, Corporate Insurance, Wholesale).
     */
    public function createPriceBook(array $data): array;

    /**
     * Get price book by ID.
     */
    public function getPriceBook(int $id): ?array;

    /**
     * Get price book by unique code within tenant.
     */
    public function getPriceBookByCode(string $code): ?array;

    /**
     * List price books with optional filters.
     */
    public function listPriceBooks(array $filters = []): array;

    /**
     * Update price book details.
     */
    public function updatePriceBook(int $id, array $data): array;

    /**
     * Delete (soft-delete) price book.
     */
    public function deletePriceBook(int $id): bool;

    /**
     * Get the tenant's default price book.
     */
    public function getDefaultPriceBook(): ?array;

    /**
     * Set or update rate for an item in a specific price book.
     * Options may include: room_type, min_qty, coverage_type, coverage_amount, patient_copay.
     */
    public function setRate(int $priceBookId, int $itemId, float $baseRate, array $options = []): array;

    /**
     * Get rate for an item in a price book, optionally matching room_type.
     */
    public function getRate(int $priceBookId, int $itemId, ?string $roomType = null): ?array;

    /**
     * List all rates defined under a price book.
     */
    public function listRates(int $priceBookId, array $filters = []): array;

    /**
     * Delete a rate entry.
     */
    public function deleteRate(int $id): bool;

    /**
     * Resolve final price and coverage details for an item:
     * - Checks specific price book (or default price book).
     * - Matches room/class tier if provided.
     * - Computes coverage_amount and patient_copay.
     * - Gracefully falls back to item's base_price if no explicit rate exists.
     */
    public function resolvePrice(int $itemId, ?int $priceBookId = null, float $qty = 1.0, array $options = []): array;

    /**
     * Create a split rule for an item or price book (e.g., Jasa Medis 60%, Jasa Sarana 40%).
     */
    public function createSplitRule(array $data): array;

    /**
     * List split rules with optional filters.
     */
    public function listSplitRules(array $filters = []): array;

    /**
     * Delete a split rule by ID.
     */
    public function deleteSplitRule(int $id): bool;

    /**
     * Calculate split components for a given item and total billed amount.
     * Returns list of fee allocations with calculated amounts.
     */
    public function calculateSplits(int $itemId, float $actualAmount, ?int $priceBookId = null, ?int $performerContactId = null): array;

    /**
     * Record transactional fee allocations for an invoice and its items.
     */
    public function recordInvoiceSplits(int $invoiceId, array $items): array;

    /**
     * List fee allocations with filters (by invoice, beneficiary, status: accrued, paid, cancelled).
     */
    public function listAllocations(array $filters = []): array;

    /**
     * Get summary of accrued, paid, and total fees for a beneficiary contact (doctor/staff/partner).
     */
    public function getBeneficiarySummary(int $beneficiaryContactId): array;

    /**
     * Disburse / settle accrued fee allocations for a beneficiary.
     * Marks allocations as 'paid' with settlement reference and optional finance journal integration.
     */
    public function settleBeneficiaryFees(int $beneficiaryContactId, array $allocationIds, ?string $settlementReference = null, ?string $paymentAccount = null): array;
}
