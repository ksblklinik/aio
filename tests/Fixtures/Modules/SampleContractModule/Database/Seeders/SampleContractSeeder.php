<?php

declare(strict_types=1);

namespace Tests\Fixtures\Modules\SampleContractModule\Database\Seeders;

use Core\Database\Seeder;

class SampleContractSeeder extends Seeder
{
    public function run(): void
    {
        $this->db->insert('sample_contracts', [
            'tenant_id' => 1,
            'title' => 'Initial Seeded Contract',
        ]);
    }
}
