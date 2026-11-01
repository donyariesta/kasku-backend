<?php

namespace Database\Seeders;

use App\Models\Type;
use App\Models\Tenant;
use App\Support\TypeCode;
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
            'code' => TypeCode::EXPENSE_COLLECTION_INCENTIVE,
            'tenantId' => $tenant->id,
            'group' => 'expenses',
            'type' => 'Apresiasi Setoran',
        ],
        ['description' => 'Apresiasi setoran']
        );

        Type::updateOrCreate(
            [
                'isSystem' => true,
                'code' => TypeCode::MEMBER_VACANT,
                'tenantId' => $tenant->id,
                'group' => 'members',
                'type' => 'Kosong',
            ],
            ['description' => 'Rumah kosong / tidak dihuni']
        );
    }
}
