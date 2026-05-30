<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $tenants = DB::table('Tenant')->pluck('id');

        foreach ($tenants as $tenantId) {
            $defaultAccountId = DB::table('FundsAccount')
                ->where('tenantId', $tenantId)
                ->orderBy('createdAt')
                ->value('id');

            if (!$defaultAccountId) {
                continue;
            }

            DB::table('Payment')
                ->where('tenantId', $tenantId)
                ->whereNull('fundsAccountId')
                ->update(['fundsAccountId' => $defaultAccountId]);

            DB::table('Expense')
                ->where('tenantId', $tenantId)
                ->whereNull('fundsAccountId')
                ->update(['fundsAccountId' => $defaultAccountId]);
        }
    }

    public function down(): void
    {
        // No-op: cannot reliably restore previous null values.
    }
};
