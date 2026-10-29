<?php

declare(strict_types=1);

namespace App\Modules\Tariff\Services;

use App\Modules\Tariff\Contracts\TariffRegistryInterface;
use Core\Database\Connection;
use Core\Database\TenantContext;
use InvalidArgumentException;
use PDO;

class TariffService implements TariffRegistryInterface
{
    public function __construct(
        protected Connection $db,
        protected TenantContext $tenantContext
    ) {}

    protected function getTenantId(): int
    {
        $id = $this->tenantContext->getTenantId();
        return $id !== null ? (int)$id : 1;
    }

    // =========================================================================
    // 1. PRICE BOOKS MANAGEMENT
    // =========================================================================

    public function createPriceBook(array $data): array
    {
        $tenantId = $this->getTenantId();

        if (empty($data['code']) || empty($data['name'])) {
            throw new InvalidArgumentException("Kode dan Nama Buku Tarif wajib diisi.");
        }

        $code = strtoupper(trim((string)$data['code']));
        $name = trim((string)$data['name']);
        $description = $data['description'] ?? null;
        $payerContactId = !empty($data['payer_contact_id']) ? (int)$data['payer_contact_id'] : null;
        $currency = !empty($data['currency']) ? strtoupper(trim((string)$data['currency'])) : 'IDR';
        $isActive = isset($data['is_active']) ? (int)(bool)$data['is_active'] : 1;
        $isDefault = isset($data['is_default']) ? (int)(bool)$data['is_default'] : 0;
        $validFrom = $data['valid_from'] ?? null;
        $validUntil = $data['valid_until'] ?? null;

        // Jika diset default, reset default lain di tenant ini
        if ($isDefault === 1) {
            $stmt = $this->db->pdo()->prepare("UPDATE tariff_price_books SET is_default = 0 WHERE tenant_id = ?");
            $stmt->execute([$tenantId]);
        }

        $stmt = $this->db->pdo()->prepare("
            INSERT INTO tariff_price_books (
                tenant_id, code, name, description, payer_contact_id, currency,
                is_active, is_default, valid_from, valid_until, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([
            $tenantId, $code, $name, $description, $payerContactId, $currency,
            $isActive, $isDefault, $validFrom, $validUntil
        ]);

        $id = (int)$this->db->pdo()->lastInsertId();
        return (array)$this->getPriceBook($id);
    }

    public function getPriceBook(int $id): ?array
    {
        $tenantId = $this->getTenantId();
        $stmt = $this->db->pdo()->prepare("
            SELECT pb.*, c.name as payer_name
            FROM tariff_price_books pb
            LEFT JOIN contacts c ON c.id = pb.payer_contact_id
            WHERE pb.id = ? AND pb.tenant_id = ? AND pb.deleted_at IS NULL
        ");
        $stmt->execute([$id, $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function getPriceBookByCode(string $code): ?array
    {
        $tenantId = $this->getTenantId();
        $stmt = $this->db->pdo()->prepare("
            SELECT pb.*, c.name as payer_name
            FROM tariff_price_books pb
            LEFT JOIN contacts c ON c.id = pb.payer_contact_id
            WHERE pb.code = ? AND pb.tenant_id = ? AND pb.deleted_at IS NULL
        ");
        $stmt->execute([strtoupper(trim($code)), $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function listPriceBooks(array $filters = []): array
    {
        $tenantId = $this->getTenantId();
        $query = "
            SELECT pb.*, c.name as payer_name
            FROM tariff_price_books pb
            LEFT JOIN contacts c ON c.id = pb.payer_contact_id
            WHERE pb.tenant_id = ? AND pb.deleted_at IS NULL
        ";
        $params = [$tenantId];

        if (isset($filters['is_active'])) {
            $query .= " AND pb.is_active = ?";
            $params[] = (int)(bool)$filters['is_active'];
        }

        if (!empty($filters['payer_contact_id'])) {
            $query .= " AND pb.payer_contact_id = ?";
            $params[] = (int)$filters['payer_contact_id'];
        }

        if (!empty($filters['search'])) {
            $query .= " AND (pb.name LIKE ? OR pb.code LIKE ?)";
            $term = '%' . trim((string)$filters['search']) . '%';
            $params[] = $term;
            $params[] = $term;
        }

        $query .= " ORDER BY pb.is_default DESC, pb.name ASC";
        $stmt = $this->db->pdo()->prepare($query);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function updatePriceBook(int $id, array $data): array
    {
        $tenantId = $this->getTenantId();
        $existing = $this->getPriceBook($id);
        if (!$existing) {
            throw new InvalidArgumentException("Buku tarif dengan ID {$id} tidak ditemukan.");
        }

        $isDefault = isset($data['is_default']) ? (int)(bool)$data['is_default'] : $existing['is_default'];

        if ($isDefault === 1 && (int)$existing['is_default'] !== 1) {
            $stmt = $this->db->pdo()->prepare("UPDATE tariff_price_books SET is_default = 0 WHERE tenant_id = ?");
            $stmt->execute([$tenantId]);
        }

        $code = isset($data['code']) ? strtoupper(trim((string)$data['code'])) : $existing['code'];
        $name = isset($data['name']) ? trim((string)$data['name']) : $existing['name'];
        $description = array_key_exists('description', $data) ? $data['description'] : $existing['description'];
        $payerContactId = array_key_exists('payer_contact_id', $data) ? $data['payer_contact_id'] : $existing['payer_contact_id'];
        $currency = isset($data['currency']) ? strtoupper(trim((string)$data['currency'])) : $existing['currency'];
        $isActive = isset($data['is_active']) ? (int)(bool)$data['is_active'] : $existing['is_active'];
        $validFrom = array_key_exists('valid_from', $data) ? $data['valid_from'] : $existing['valid_from'];
        $validUntil = array_key_exists('valid_until', $data) ? $data['valid_until'] : $existing['valid_until'];

        $stmt = $this->db->pdo()->prepare("
            UPDATE tariff_price_books SET
                code = ?, name = ?, description = ?, payer_contact_id = ?, currency = ?,
                is_active = ?, is_default = ?, valid_from = ?, valid_until = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ? AND tenant_id = ?
        ");
        $stmt->execute([
            $code, $name, $description, $payerContactId, $currency,
            $isActive, $isDefault, $validFrom, $validUntil, $id, $tenantId
        ]);

        return (array)$this->getPriceBook($id);
    }

    public function deletePriceBook(int $id): bool
    {
        $tenantId = $this->getTenantId();
        $stmt = $this->db->pdo()->prepare("
            UPDATE tariff_price_books SET deleted_at = CURRENT_TIMESTAMP WHERE id = ? AND tenant_id = ?
        ");
        return $stmt->execute([$id, $tenantId]);
    }

    public function getDefaultPriceBook(): ?array
    {
        $tenantId = $this->getTenantId();
        $stmt = $this->db->pdo()->prepare("
            SELECT * FROM tariff_price_books
            WHERE tenant_id = ? AND is_default = 1 AND is_active = 1 AND deleted_at IS NULL
            LIMIT 1
        ");
        $stmt->execute([$tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            return $row;
        }

        // Fallback to first active price book
        $stmt = $this->db->pdo()->prepare("
            SELECT * FROM tariff_price_books
            WHERE tenant_id = ? AND is_active = 1 AND deleted_at IS NULL
            ORDER BY id ASC LIMIT 1
        ");
        $stmt->execute([$tenantId]);
        $fallback = $stmt->fetch(PDO::FETCH_ASSOC);

        return $fallback ?: null;
    }

    // =========================================================================
    // 2. RATES MATRIX (TARIF LAYANAN & BARANG)
    // =========================================================================

    public function setRate(int $priceBookId, int $itemId, float $baseRate, array $options = []): array
    {
        $tenantId = $this->getTenantId();
        $roomType = !empty($options['room_type']) ? trim((string)$options['room_type']) : null;
        $minQty = isset($options['min_qty']) ? (float)$options['min_qty'] : 1.0;
        $coverageType = !empty($options['coverage_type']) ? trim((string)$options['coverage_type']) : 'fixed';
        $coverageAmount = array_key_exists('coverage_amount', $options) && $options['coverage_amount'] !== null ? (float)$options['coverage_amount'] : null;
        $patientCopay = isset($options['patient_copay']) ? (float)$options['patient_copay'] : 0.0;

        // Cek existing
        $checkStmt = $this->db->pdo()->prepare("
            SELECT id FROM tariff_rates
            WHERE tenant_id = ? AND price_book_id = ? AND item_id = ?
              AND (room_type = ? OR (room_type IS NULL AND ? IS NULL))
              AND min_qty = ?
        ");
        $checkStmt->execute([$tenantId, $priceBookId, $itemId, $roomType, $roomType, $minQty]);
        $existingId = $checkStmt->fetchColumn();

        if ($existingId) {
            $stmt = $this->db->pdo()->prepare("
                UPDATE tariff_rates SET
                    base_rate = ?, coverage_type = ?, coverage_amount = ?, patient_copay = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ? AND tenant_id = ?
            ");
            $stmt->execute([$baseRate, $coverageType, $coverageAmount, $patientCopay, (int)$existingId, $tenantId]);
            $rateId = (int)$existingId;
        } else {
            $stmt = $this->db->pdo()->prepare("
                INSERT INTO tariff_rates (
                    tenant_id, price_book_id, item_id, room_type, base_rate, min_qty,
                    coverage_type, coverage_amount, patient_copay, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ");
            $stmt->execute([
                $tenantId, $priceBookId, $itemId, $roomType, $baseRate, $minQty,
                $coverageType, $coverageAmount, $patientCopay
            ]);
            $rateId = (int)$this->db->pdo()->lastInsertId();
        }

        $stmt = $this->db->pdo()->prepare("
            SELECT tr.*, i.name as item_name, i.code as item_code, pb.name as price_book_name
            FROM tariff_rates tr
            JOIN items i ON i.id = tr.item_id
            JOIN tariff_price_books pb ON pb.id = tr.price_book_id
            WHERE tr.id = ? AND tr.tenant_id = ?
        ");
        $stmt->execute([$rateId, $tenantId]);
        return (array)$stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getRate(int $priceBookId, int $itemId, ?string $roomType = null): ?array
    {
        $tenantId = $this->getTenantId();

        if ($roomType !== null) {
            $stmt = $this->db->pdo()->prepare("
                SELECT tr.*, i.name as item_name, i.code as item_code
                FROM tariff_rates tr
                JOIN items i ON i.id = tr.item_id
                WHERE tr.tenant_id = ? AND tr.price_book_id = ? AND tr.item_id = ? AND tr.room_type = ?
                LIMIT 1
            ");
            $stmt->execute([$tenantId, $priceBookId, $itemId, $roomType]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return $row;
            }
        }

        // Fallback to room_type IS NULL
        $stmt = $this->db->pdo()->prepare("
            SELECT tr.*, i.name as item_name, i.code as item_code
            FROM tariff_rates tr
            JOIN items i ON i.id = tr.item_id
            WHERE tr.tenant_id = ? AND tr.price_book_id = ? AND tr.item_id = ? AND tr.room_type IS NULL
            LIMIT 1
        ");
        $stmt->execute([$tenantId, $priceBookId, $itemId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function listRates(int $priceBookId, array $filters = []): array
    {
        $tenantId = $this->getTenantId();
        $query = "
            SELECT tr.*, i.name as item_name, i.code as item_code, i.type as item_type, i.selling_price as base_price
            FROM tariff_rates tr
            JOIN items i ON i.id = tr.item_id
            WHERE tr.tenant_id = ? AND tr.price_book_id = ?
        ";
        $params = [$tenantId, $priceBookId];

        if (!empty($filters['room_type'])) {
            $query .= " AND tr.room_type = ?";
            $params[] = trim((string)$filters['room_type']);
        }

        if (!empty($filters['search'])) {
            $query .= " AND (i.name LIKE ? OR i.code LIKE ?)";
            $term = '%' . trim((string)$filters['search']) . '%';
            $params[] = $term;
            $params[] = $term;
        }

        $query .= " ORDER BY i.name ASC, tr.min_qty ASC";
        $stmt = $this->db->pdo()->prepare($query);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function deleteRate(int $id): bool
    {
        $tenantId = $this->getTenantId();
        $stmt = $this->db->pdo()->prepare("DELETE FROM tariff_rates WHERE id = ? AND tenant_id = ?");
        return $stmt->execute([$id, $tenantId]);
    }

    public function resolvePrice(int $itemId, ?int $priceBookId = null, float $qty = 1.0, array $options = []): array
    {
        $tenantId = $this->getTenantId();

        // 1. Dapatkan metadata item
        $stmt = $this->db->pdo()->prepare("SELECT * FROM items WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$itemId, $tenantId]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$item) {
            throw new InvalidArgumentException("Item dengan ID {$itemId} tidak ditemukan.");
        }

        // 2. Resolve price book
        if ($priceBookId === null) {
            $defaultBook = $this->getDefaultPriceBook();
            $priceBookId = $defaultBook ? (int)$defaultBook['id'] : null;
        }

        $roomType = $options['room_type'] ?? null;
        $matchedRate = null;

        if ($priceBookId !== null) {
            // Cek rate berjenjang (kuantitas dan kelas ruangan)
            $query = "
                SELECT * FROM tariff_rates
                WHERE tenant_id = ? AND price_book_id = ? AND item_id = ? AND min_qty <= ?
            ";
            $params = [$tenantId, $priceBookId, $itemId, $qty];

            if ($roomType !== null) {
                $query .= " AND (room_type = ? OR room_type IS NULL) ORDER BY (room_type = ?) DESC, min_qty DESC LIMIT 1";
                $params[] = $roomType;
                $params[] = $roomType;
            } else {
                $query .= " AND room_type IS NULL ORDER BY min_qty DESC LIMIT 1";
            }

            $stmt = $this->db->pdo()->prepare($query);
            $stmt->execute($params);
            $matchedRate = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        if ($matchedRate) {
            $rateId = (int)$matchedRate['id'];
            $baseRate = (float)$matchedRate['base_rate'];
            $coverageType = $matchedRate['coverage_type'];

            if ($coverageType === 'percentage') {
                $pct = (float)$matchedRate['coverage_amount'];
                $coverageAmount = round($baseRate * ($pct / 100.0), 2);
            } elseif ($coverageType === 'cap_limit') {
                $cap = (float)$matchedRate['coverage_amount'];
                $coverageAmount = min($baseRate, $cap);
            } else { // fixed
                $coverageAmount = $matchedRate['coverage_amount'] !== null ? (float)$matchedRate['coverage_amount'] : $baseRate;
                $coverageAmount = min($baseRate, $coverageAmount);
            }

            $patientCopay = max(0.0, $baseRate - $coverageAmount);
            $unitPrice = $baseRate;
        } else {
            // Fallback ke item selling_price / base_price
            $rateId = null;
            $unitPrice = (float)($item['selling_price'] ?? $item['base_price'] ?? 0.0);
            $coverageAmount = 0.0;
            $patientCopay = $unitPrice;
        }

        $subtotal = round($unitPrice * $qty, 2);
        $totalCoverage = round($coverageAmount * $qty, 2);
        $totalCopay = round($patientCopay * $qty, 2);

        return [
            'item_id' => $itemId,
            'item_code' => $item['code'],
            'item_name' => $item['name'],
            'price_book_id' => $priceBookId,
            'rate_id' => $rateId,
            'unit_price' => $unitPrice,
            'qty' => $qty,
            'subtotal' => $subtotal,
            'coverage_amount' => $coverageAmount,
            'total_coverage' => $totalCoverage,
            'patient_copay' => $patientCopay,
            'total_copay' => $totalCopay,
            'is_custom_rate' => ($rateId !== null)
        ];
    }

    // =========================================================================
    // 3. SPLIT RULES (KOMPOSISI TARIF / BAGI HASIL)
    // =========================================================================

    public function createSplitRule(array $data): array
    {
        $tenantId = $this->getTenantId();

        if (empty($data['name']) || empty($data['recipient_type']) || !isset($data['split_value'])) {
            throw new InvalidArgumentException("Nama aturan, tipe penerima, dan nilai bagi hasil wajib diisi.");
        }

        $name = trim((string)$data['name']);
        $recipientType = trim((string)$data['recipient_type']); // performer, facility, organization, account
        $splitType = !empty($data['split_type']) ? trim((string)$data['split_type']) : 'percentage';
        $splitValue = (float)$data['split_value'];
        $itemId = !empty($data['item_id']) ? (int)$data['item_id'] : null;
        $priceBookId = !empty($data['price_book_id']) ? (int)$data['price_book_id'] : null;
        $beneficiaryContactId = !empty($data['default_beneficiary_contact_id']) ? (int)$data['default_beneficiary_contact_id'] : null;
        $targetAccountCode = !empty($data['target_account_code']) ? trim((string)$data['target_account_code']) : null;

        // Validasi: akumulasi persentase tidak boleh > 100%
        if ($splitType === 'percentage') {
            $stmt = $this->db->pdo()->prepare("
                SELECT SUM(split_value) FROM tariff_split_rules
                WHERE tenant_id = ? AND split_type = 'percentage'
                  AND (item_id = ? OR (item_id IS NULL AND ? IS NULL))
                  AND (price_book_id = ? OR (price_book_id IS NULL AND ? IS NULL))
            ");
            $stmt->execute([$tenantId, $itemId, $itemId, $priceBookId, $priceBookId]);
            $existingSum = (float)$stmt->fetchColumn();

            if (($existingSum + $splitValue) > 100.0) {
                throw new InvalidArgumentException("Total persentase aturan bagi hasil tidak boleh melebihi 100% (saat ini: {$existingSum}% + {$splitValue}%).");
            }
        }

        $stmt = $this->db->pdo()->prepare("
            INSERT INTO tariff_split_rules (
                tenant_id, item_id, price_book_id, name, recipient_type,
                default_beneficiary_contact_id, split_type, split_value, target_account_code,
                created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([
            $tenantId, $itemId, $priceBookId, $name, $recipientType,
            $beneficiaryContactId, $splitType, $splitValue, $targetAccountCode
        ]);

        $id = (int)$this->db->pdo()->lastInsertId();

        $fetchStmt = $this->db->pdo()->prepare("
            SELECT sr.*, i.name as item_name, pb.name as price_book_name, c.name as beneficiary_name
            FROM tariff_split_rules sr
            LEFT JOIN items i ON i.id = sr.item_id
            LEFT JOIN tariff_price_books pb ON pb.id = sr.price_book_id
            LEFT JOIN contacts c ON c.id = sr.default_beneficiary_contact_id
            WHERE sr.id = ? AND sr.tenant_id = ?
        ");
        $fetchStmt->execute([$id, $tenantId]);
        return (array)$fetchStmt->fetch(PDO::FETCH_ASSOC);
    }

    public function listSplitRules(array $filters = []): array
    {
        $tenantId = $this->getTenantId();
        $query = "
            SELECT sr.*, i.name as item_name, pb.name as price_book_name, c.name as beneficiary_name
            FROM tariff_split_rules sr
            LEFT JOIN items i ON i.id = sr.item_id
            LEFT JOIN tariff_price_books pb ON pb.id = sr.price_book_id
            LEFT JOIN contacts c ON c.id = sr.default_beneficiary_contact_id
            WHERE sr.tenant_id = ?
        ";
        $params = [$tenantId];

        if (!empty($filters['item_id'])) {
            $query .= " AND (sr.item_id = ? OR sr.item_id IS NULL)";
            $params[] = (int)$filters['item_id'];
        }

        if (!empty($filters['price_book_id'])) {
            $query .= " AND (sr.price_book_id = ? OR sr.price_book_id IS NULL)";
            $params[] = (int)$filters['price_book_id'];
        }

        $query .= " ORDER BY sr.id ASC";
        $stmt = $this->db->pdo()->prepare($query);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function deleteSplitRule(int $id): bool
    {
        $tenantId = $this->getTenantId();
        $stmt = $this->db->pdo()->prepare("DELETE FROM tariff_split_rules WHERE id = ? AND tenant_id = ?");
        return $stmt->execute([$id, $tenantId]);
    }

    public function calculateSplits(int $itemId, float $actualAmount, ?int $priceBookId = null, ?int $performerContactId = null): array
    {
        $tenantId = $this->getTenantId();

        $stmt = $this->db->pdo()->prepare("
            SELECT * FROM tariff_split_rules
            WHERE tenant_id = ?
              AND (item_id = ? OR item_id IS NULL)
              AND (price_book_id = ? OR price_book_id IS NULL)
            ORDER BY (item_id IS NOT NULL) DESC, (price_book_id IS NOT NULL) DESC, id ASC
        ");
        $stmt->execute([$tenantId, $itemId, $priceBookId]);
        $rules = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $results = [];
        foreach ($rules as $rule) {
            $splitType = $rule['split_type'];
            $splitValue = (float)$rule['split_value'];

            if ($splitType === 'percentage') {
                $calcAmount = round($actualAmount * ($splitValue / 100.0), 2);
            } else { // fixed_amount
                $calcAmount = min($actualAmount, $splitValue);
            }

            // Tentukan beneficiary
            $beneficiaryId = $rule['default_beneficiary_contact_id'];
            if ($rule['recipient_type'] === 'performer' && $performerContactId !== null) {
                $beneficiaryId = $performerContactId;
            }

            $results[] = [
                'split_rule_id' => (int)$rule['id'],
                'item_id' => $itemId,
                'component_name' => $rule['name'],
                'recipient_type' => $rule['recipient_type'],
                'split_type' => $splitType,
                'split_value' => $splitValue,
                'amount' => $calcAmount,
                'beneficiary_contact_id' => $beneficiaryId ? (int)$beneficiaryId : null,
                'target_account_code' => $rule['target_account_code']
            ];
        }

        return $results;
    }

    // =========================================================================
    // 4. FEE ALLOCATIONS & SETTLEMENT
    // =========================================================================

    public function recordInvoiceSplits(int $invoiceId, array $items): array
    {
        $tenantId = $this->getTenantId();

        $hasInvoiceItemIds = false;
        foreach ($items as $item) {
            if (!empty($item['invoice_item_id'])) {
                $hasInvoiceItemIds = true;
                break;
            }
        }

        // Idempotency batch: jika item tidak memiliki invoice_item_id dan invoice sudah pernah diproses
        if (!$hasInvoiceItemIds) {
            $existing = $this->db->select("
                SELECT * FROM tariff_fee_allocations 
                WHERE tenant_id = ? AND invoice_id = ?
            ", [$tenantId, $invoiceId]);

            if (!empty($existing)) {
                return $existing;
            }
        }

        $allocations = [];

        foreach ($items as $item) {
            $itemId = (int)($item['item_id'] ?? $item['id']);
            $qty = isset($item['quantity']) ? (float)$item['quantity'] : (float)($item['qty'] ?? 1.0);
            $unitPrice = isset($item['unit_price']) ? (float)$item['unit_price'] : 0.0;
            $lineTotal = isset($item['total']) ? (float)$item['total'] : round($unitPrice * $qty, 2);
            $performerContactId = !empty($item['performer_contact_id']) ? (int)$item['performer_contact_id'] : null;
            $priceBookId = !empty($item['price_book_id']) ? (int)$item['price_book_id'] : null;
            $invoiceItemId = !empty($item['invoice_item_id']) ? (int)$item['invoice_item_id'] : null;

            $splits = $this->calculateSplits($itemId, $lineTotal, $priceBookId, $performerContactId);

            foreach ($splits as $split) {
                // Idempotency per item jika invoice_item_id terdefinisi
                if ($invoiceItemId !== null) {
                    $existing = $this->db->selectOne("
                        SELECT id FROM tariff_fee_allocations 
                        WHERE tenant_id = ? AND invoice_id = ? AND invoice_item_id = ? AND split_rule_id = ?
                    ", [$tenantId, $invoiceId, $invoiceItemId, $split['split_rule_id']]);

                    if ($existing) {
                        $allocations[] = array_merge($split, [
                            'id' => (int)$existing['id'],
                            'invoice_id' => $invoiceId,
                            'status' => 'accrued',
                        ]);
                        continue;
                    }
                }

                $stmt = $this->db->pdo()->prepare("
                    INSERT INTO tariff_fee_allocations (
                        tenant_id, invoice_id, invoice_item_id, split_rule_id, item_id,
                        beneficiary_contact_id, component_name, amount, status,
                        created_at, updated_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'accrued', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                ");
                $stmt->execute([
                    $tenantId, $invoiceId, $invoiceItemId, $split['split_rule_id'], $itemId,
                    $split['beneficiary_contact_id'], $split['component_name'], $split['amount']
                ]);

                $allocationId = (int)$this->db->pdo()->lastInsertId();
                $allocations[] = array_merge($split, [
                    'id' => $allocationId,
                    'invoice_id' => $invoiceId,
                    'status' => 'accrued'
                ]);
            }
        }

        return $allocations;
    }

    public function listAllocations(array $filters = []): array
    {
        $tenantId = $this->getTenantId();
        $query = "
            SELECT fa.*, c.name as beneficiary_name, i.name as item_name, bi.invoice_number
            FROM tariff_fee_allocations fa
            LEFT JOIN contacts c ON c.id = fa.beneficiary_contact_id
            LEFT JOIN items i ON i.id = fa.item_id
            LEFT JOIN invoices bi ON bi.id = fa.invoice_id
            WHERE fa.tenant_id = ?
        ";
        $params = [$tenantId];

        if (!empty($filters['invoice_id'])) {
            $query .= " AND fa.invoice_id = ?";
            $params[] = (int)$filters['invoice_id'];
        }

        if (!empty($filters['beneficiary_contact_id'])) {
            $query .= " AND fa.beneficiary_contact_id = ?";
            $params[] = (int)$filters['beneficiary_contact_id'];
        }

        if (!empty($filters['status'])) {
            $query .= " AND fa.status = ?";
            $params[] = trim((string)$filters['status']);
        }

        $query .= " ORDER BY fa.id DESC";
        $stmt = $this->db->pdo()->prepare($query);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getBeneficiarySummary(int $beneficiaryContactId): array
    {
        $tenantId = $this->getTenantId();

        $stmt = $this->db->pdo()->prepare("
            SELECT
                COUNT(id) as total_count,
                COALESCE(SUM(CASE WHEN status = 'accrued' THEN amount ELSE 0 END), 0) as total_accrued,
                COALESCE(SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END), 0) as total_paid,
                COALESCE(SUM(amount), 0) as total_overall
            FROM tariff_fee_allocations
            WHERE tenant_id = ? AND beneficiary_contact_id = ? AND status != 'cancelled'
        ");
        $stmt->execute([$tenantId, $beneficiaryContactId]);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            'beneficiary_contact_id' => $beneficiaryContactId,
            'total_allocations' => (int)($stats['total_count'] ?? 0),
            'total_accrued' => (float)($stats['total_accrued'] ?? 0.0),
            'total_paid' => (float)($stats['total_paid'] ?? 0.0),
            'total_overall' => (float)($stats['total_overall'] ?? 0.0),
        ];
    }

    public function settleBeneficiaryFees(int $beneficiaryContactId, array $allocationIds, ?string $settlementReference = null, ?string $paymentAccount = null): array
    {
        $tenantId = $this->getTenantId();

        if (empty($allocationIds)) {
            throw new InvalidArgumentException("Daftar ID alokasi fee tidak boleh kosong.");
        }

        $reference = $settlementReference ?: 'SETTLE/' . date('Ymd') . '/' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));

        $placeholders = implode(',', array_fill(0, count($allocationIds), '?'));
        $checkStmt = $this->db->pdo()->prepare("
            SELECT fa.id, fa.amount, sr.target_account_code FROM tariff_fee_allocations fa
            LEFT JOIN tariff_split_rules sr ON sr.id = fa.split_rule_id
            WHERE fa.id IN ({$placeholders}) AND fa.tenant_id = ? AND fa.beneficiary_contact_id = ? AND fa.status = 'accrued'
        ");
        $checkParams = array_merge($allocationIds, [$tenantId, $beneficiaryContactId]);
        $checkStmt->execute($checkParams);
        $validRows = $checkStmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($validRows)) {
            throw new InvalidArgumentException("Tidak ada alokasi fee 'accrued' yang valid untuk diselesaikan.");
        }

        $totalAmount = 0.0;
        $validIds = [];
        foreach ($validRows as $row) {
            $validIds[] = (int)$row['id'];
            $totalAmount += (float)$row['amount'];
        }

        $validPlaceholders = implode(',', array_fill(0, count($validIds), '?'));
        $updateStmt = $this->db->pdo()->prepare("
            UPDATE tariff_fee_allocations SET
                status = 'paid', settlement_reference = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id IN ({$validPlaceholders}) AND tenant_id = ?
        ");
        $updateParams = array_merge([$reference], $validIds, [$tenantId]);
        $updateStmt->execute($updateParams);

        return [
            'settlement_reference' => $reference,
            'beneficiary_contact_id' => $beneficiaryContactId,
            'settled_count' => count($validIds),
            'total_amount' => round($totalAmount, 2),
            'status' => 'paid'
        ];
    }

    public function cancelInvoiceSplits(int $invoiceId): int
    {
        $tenantId = $this->getTenantId();
        $stmt = $this->db->pdo()->prepare("
            UPDATE tariff_fee_allocations 
            SET status = 'cancelled', updated_at = CURRENT_TIMESTAMP 
            WHERE invoice_id = ? AND tenant_id = ? AND status = 'accrued'
        ");
        $stmt->execute([$invoiceId, $tenantId]);
        return $stmt->rowCount();
    }
}
