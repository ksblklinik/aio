<?php

declare(strict_types=1);

namespace App\Modules\Contact\Contracts;

use App\Modules\Contact\Models\Contact;

interface ContactRegistryInterface
{
    /**
     * Daftarkan kontak baru (perorangan atau organisasi)
     */
    public function create(array $attributes): Contact;

    /**
     * Perbarui data kontak
     */
    public function update(int $id, array $attributes): Contact;

    /**
     * Hapus kontak (soft delete)
     */
    public function delete(int $id): bool;

    /**
     * Temukan kontak berdasarkan ID primer
     */
    public function find(int $id): ?Contact;

    /**
     * Temukan kontak berdasarkan kode kontak (misal CNT-2026-00001)
     */
    public function findByCode(string $code): ?Contact;

    /**
     * Ambil seluruh kontak yang mengemban peran tertentu (e.g. 'customer', 'vendor', 'patient')
     *
     * @return array<Contact>
     */
    public function getByRole(string $role, array $filters = []): array;

    /**
     * Tetapkan peran baru ke kontak
     */
    public function addRole(int $contactId, string $role, array $metadata = [], bool $isPrimary = false): void;

    /**
     * Hapus peran dari kontak
     */
    public function removeRole(int $contactId, string $role): void;

    /**
     * Cek apakah kontak mengemban peran tertentu
     */
    public function hasRole(int $contactId, string $role): bool;

    /**
     * Cari kontak secara cepat (nama, kode, email, telepon)
     *
     * @return array<Contact>
     */
    public function search(string $query, int $limit = 20): array;

    /**
     * Tambah alamat fisik kontak
     */
    public function addAddress(int $contactId, array $addressData): int;

    /**
     * Hubungkan relasi/afiliasi antar kontak (misal: Budi bekerja di PT Maju)
     */
    public function linkAffiliation(int $personId, int $organizationId, string $relationType = 'employee_of', ?string $jobTitle = null): int;
}
