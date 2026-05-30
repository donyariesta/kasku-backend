<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $depositAccountIds = DB::table('FundsAccount')
            ->where('isSystem', true)
            ->where('name', 'Deposit')
            ->pluck('id');

        if ($depositAccountIds->isEmpty()) {
            return;
        }

        DB::table('FundsTransfer')
            ->whereIn('fromFundsAccountId', $depositAccountIds)
            ->delete();
    }

    public function down(): void
    {
        // Historical deposit distribution transfers cannot be restored.
    }
};
