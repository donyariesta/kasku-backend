<?php

namespace Database\Seeders;

use App\Models\FundsAccount;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

class FundsAccountSeeder extends Seeder
{
    public function run(): void
    {
        Tenant::query()->each(fn (Tenant $tenant) => self::seedForTenant($tenant));
    }

    public static function seedForTenant(Tenant $tenant): void
    {
        FundsAccount::updateOrCreate(
            [
                'tenantId' => $tenant->id,
                'name' => FundsAccount::DEPOSIT_NAME,
            ],
            [
                'active' => true,
                'monthlyAmount' => 0,
                'isSystem' => true,
            ]
        );

        FundsAccount::updateOrCreate(
            [
                'tenantId' => $tenant->id,
                'name' => 'Kas Umum',
            ],
            [
                'active' => true,
                'monthlyAmount' => 100,
                'isSystem' => false,
            ]
        );
    }
}
