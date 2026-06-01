<?php

namespace Database\Seeders;

use App\Models\FundsAccount;
use App\Models\Tenant;
use App\Services\FundsAccountMonthlyTargetService;
use Illuminate\Database\Seeder;

class FundsAccountSeeder extends Seeder
{
    public function run(): void
    {
        Tenant::query()->each(fn (Tenant $tenant) => self::seedForTenant($tenant));
    }

    public static function seedForTenant(Tenant $tenant): void
    {
        $targetService = app(FundsAccountMonthlyTargetService::class);

        $deposit = FundsAccount::updateOrCreate(
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

        if (!$deposit->monthlyTargets()->exists()) {
            $targetService->createTarget($deposit, 0, $tenant->createdAt ?? now());
        }

        $general = FundsAccount::updateOrCreate(
            [
                'tenantId' => $tenant->id,
                'name' => 'Kas Umum',
            ],
            [
                'active' => true,
                'monthlyAmount' => 0,
                'isSystem' => false,
            ]
        );

        if (!$general->monthlyTargets()->exists()) {
            $targetService->createTarget($general, 100, $tenant->createdAt ?? now());
        }
    }
}
