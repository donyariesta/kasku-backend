<?php

namespace Database\Seeders;

use App\Models\Type;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

class TypeSeeder extends Seeder
{
    public function run(): void
    {
        Tenant::query()->each(fn (Tenant $tenant) => self::seedForTenant($tenant));
    }

    public static function seedForTenant(Tenant $tenant): void
    {
        Type::updateOrCreate(
        [
            'isSystem' => true,
            'code' => 1,
            'tenantId' => $tenant->id,
            'group' => 'expenses',
            'type' => 'Apresiasi Setoran',
        ],
        ['description' => 'Apresiasi setoran']
    );
    }
}
