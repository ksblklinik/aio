# Panduan Pengembangan Modul Bisnis (Module Development Guide)

Repositori **aio** menganut arsitektur modular otonom yang mematuhi **Universal Invariants Charter** dan **Rule 08 (Zero Infrastructure Leakage)**. Setiap modul domain bisnis (seperti Billing, Inventory, Projects, Terminology) beroperasi sebagai unit independen yang terisolasi tenancy, tanpa mencemari Core framework.

---

## 1. Konsep & Prinsip Desain Modul

1. **Autonomous Bounded Context**: Seluruh entitas, rute, controller, migrasi, dan logika bisnis suatu domain berada di dalam direktori modul masing-masing (`app/Modules/<ModuleName>/`).
2. **Zero Infrastructure Leakage**: Modul bisnis tidak boleh membuat atau memodifikasi kapabilitas platform (seperti event bus, auth engine, queue driver, database connection pooling). Modul hanya *mengonsumsi* layanan Core via Service Container atau Service Provider.
3. **Tenancy By Design**: Semua operasi database modul wajib memanfaatkan `TenantScope` atau model yang menyaring `tenant_id` secara otomatis.
4. **Semantic UI & Anti-Slop**: Halaman frontend React/Inertia modul wajib memanfaatkan CSS semantic design tokens (`var(--bg-card)`, `var(--text-main)`, dll.) dan mendukung penuh mode gelap/terang tanpa inline styles keras.

---

## 2. Struktur Anatomi Modul

Setiap modul memiliki struktur direktori terstandar:

```
app/Modules/Example/
├── Controllers/
│   └── ExampleController.php        # HTTP Controller
├── Database/
│   └── Migrations/
│       └── 001_create_examples_table.sql # Migrasi database modular
├── Models/
│   └── Example.php                  # Model / Active Record terisolasi tenancy
├── ExampleServiceProvider.php       # Service provider turunan ModuleProvider
├── module.json                      # Metadata & manifest perizinan modul
└── routes.php                       # Definisi rute HTTP & middleware modul
```

---

## 3. Membuat Modul Baru via CLI

Gunakan perintah `php aio make:module` untuk melakukan *scaffolding* modul lengkap dalam hitungan detik:

```bash
php aio make:module Invoices --category=finance
```

### Parameter & Opsi:
- `name` (wajib): Nama PascalCase dari modul (contoh: `Invoices`, `Fleet`, `Warehouse`).
- `--category`: Kategori universal modul (`collaboration`, `finance`, `operations`, `logistics`, `records`, `identity`, `commerce`, `analytics`).
- `--description`: Deskripsi fungsional modul.

CLI akan otomatis menghasilkan:
- `app/Modules/Invoices/InvoicesServiceProvider.php`
- `app/Modules/Invoices/module.json`
- `app/Modules/Invoices/routes.php`
- `app/Modules/Invoices/Controllers/InvoicesController.php`
- `app/Modules/Invoices/Models/Invoices.php`
- `app/Modules/Invoices/Database/Migrations/001_create_invoices_table.sql`

---

## 4. Manifest Modul (`module.json`)

File `module.json` mendefinisikan identitas, izin akses (RBAC), dan metadata modul untuk sistem manajemen aplikasi:

```json
{
  "id": "invoices",
  "name": "Manajemen Faktur",
  "version": "1.0.0",
  "author": "Tim Pengembang",
  "description": "Pencatatan dan penerbitan faktur tagihan pelanggan.",
  "category": "finance",
  "icon": "FileText",
  "route": "/invoices",
  "dependencies": ["auth", "workspaces"],
  "is_core": false,
  "is_active": true,
  "table_name": "invoices",
  "permissions": [
    "invoices.view",
    "invoices.create",
    "invoices.edit",
    "invoices.delete",
    "invoices.manage"
  ],
  "provider": "App\\Modules\\Invoices\\InvoicesServiceProvider"
}
```

---

## 5. Service Provider (`ModuleProvider`)

Service provider modul bertugas mendaftarkan layanan, rute, pendengar event, jadwal tugas cron, atau injeksi Inertia.

```php
<?php

declare(strict_types=1);

namespace App\Modules\Invoices;

use Core\Modules\ModuleProvider;
use Core\Schedule\Schedule;

class InvoicesServiceProvider extends ModuleProvider
{
    /**
     * Daftarkan binding antarmuka ke service container.
     */
    public function register(): void
    {
        $this->singleton(InvoiceServiceInterface::class, InvoiceService::class);
    }

    /**
     * Bootstrapping rute, event listeners, dan props frontend.
     */
    public function boot(): void
    {
        // 1. Muat rute HTTP modul
        $this->loadRoutesFrom(__DIR__ . '/routes.php');

        // 2. Daftarkan event listener domain
        $this->listen(InvoicePaidEvent::class, SendInvoiceReceiptListener::class);

        // 3. Berbagi state global ke Inertia frontend (opsional)
        $this->shareInertia([
            'invoice_badge_count' => fn() => 5,
        ]);
    }

    /**
     * Jadwalkan cron task berkala khusus modul.
     */
    public function schedule(Schedule $schedule): void
    {
        $schedule->call(function () {
            // Cek faktur jatuh tempo setiap hari
        })->daily();
    }
}
```

---

## 6. Migrasi Database Modular

Migrasi diletakkan di `app/Modules/<ModuleName>/Database/Migrations/` dengan ekstensi `.sql`. Sistem migrator aio mendukung penanda `-- UP --` dan `-- DOWN --` untuk mendukung rollback otomatis:

```sql
-- UP --
CREATE TABLE IF NOT EXISTS invoices (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    customer_id INTEGER NOT NULL,
    invoice_number VARCHAR(50) NOT NULL,
    total_amount DECIMAL(15, 2) NOT NULL DEFAULT 0.00,
    status VARCHAR(20) NOT NULL DEFAULT 'draft',
    due_date DATE NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_invoices_tenant ON invoices(tenant_id);
CREATE INDEX IF NOT EXISTS idx_invoices_status ON invoices(tenant_id, status);

-- DOWN --
DROP TABLE IF EXISTS invoices;
```

Jalankan migrasi melalui CLI:
```bash
php aio migrate
```
Atau jalankan migrasi khusus modul tersebut:
```bash
php aio migrate --module=invoices
```

---

## 7. Rute & Proteksi Hak Akses (RBAC)

Definisikan rute di `routes.php` dengan memanfaatkan middleware autentikasi dan otorisasi hak akses:

```php
<?php

use App\Modules\Invoices\Controllers\InvoiceController;
use Core\Router;

/** @var Router $router */
$router->group(['middleware' => ['auth', 'tenant']], function (Router $router) {
    // Memeriksa hak akses granular via can middleware
    $router->get('/invoices', [InvoiceController::class, 'index']);
    $router->get('/invoices/create', [InvoiceController::class, 'create'])->middleware('can:invoices.create');
    $router->post('/invoices', [InvoiceController::class, 'store'])->middleware('can:invoices.create');
    $router->post('/invoices/{id}/delete', [InvoiceController::class, 'destroy'])->middleware('can:invoices.delete');
});
```

Di dalam Controller, Anda juga dapat menggunakan helper `can()`:
```php
if (!can('invoices.manage')) {
    $this->session->flash('error', 'Akses ditolak.');
    return Response::redirect('/invoices');
}
```

---

## 8. Frontend React & Inertia Pages

Gunakan komponen layout standar `AppLayout` dan terapkan CSS Semantic Design Tokens:

```tsx
import React from 'react';
import { Head, usePage } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';

interface Invoice {
  id: number;
  invoice_number: string;
  total_amount: number;
  status: string;
}

export default function Index({ invoices }: { invoices: Invoice[] }) {
  return (
    <AppLayout title="Faktur Tagihan">
      <Head title="Faktur Tagihan" />
      
      <div className="p-6 max-w-7xl mx-auto space-y-6">
        <div className="flex items-center justify-between">
          <h1 className="text-2xl font-bold tracking-tight text-[var(--text-main)]">
            Daftar Faktur
          </h1>
        </div>

        <div className="rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-4 shadow-sm">
          {/* Table content */}
        </div>
      </div>
    </AppLayout>
  );
}
```

---

## 9. Pengujian Mandiri (TDD)

Buat test unit di `tests/Unit/<ModuleName>Test.php`. Pastikan test menguji isolasi multi-tenant:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use Core\Database\Connection;
use Core\Database\Migrator;
use Core\Database\TenantContext;
use Core\Database\TenantScope;
use PHPUnit\Framework\TestCase;

class InvoicesTest extends TestCase
{
    private Connection $db;
    private TenantScope $tenantScope;

    protected function setUp(): void
    {
        $this->db = new Connection('sqlite::memory:');
        $migrator = new Migrator($this->db);
        $migrator->runMigrationsFrom(dirname(__DIR__, 2) . '/database/migrations');
        $migrator->runMigrationsFrom(dirname(__DIR__, 2) . '/app/Modules/Invoices/Database/Migrations', 'invoices');

        $tenantContext = new TenantContext();
        $this->tenantScope = new TenantScope($tenantContext, $this->db);
    }

    public function test_invoice_creation_isolated_per_tenant(): void
    {
        // Tes logika isolasi tenant di sini
    }
}
```

Jalankan test:
```bash
php vendor/bin/phpunit tests/Unit/InvoicesTest.php
```
