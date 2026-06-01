<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('FundsAccountMonthlyTarget', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('fundsAccountId')->constrained('FundsAccount')->cascadeOnDelete();
            $table->double('amount');
            $table->date('effectiveDate');
            $table->timestampTz('createdAt')->useCurrent();

            $table->index(['fundsAccountId', 'effectiveDate']);
        });

        $accounts = DB::table('FundsAccount')->get();
        foreach ($accounts as $account) {
            $effectiveDate = $account->createdAt
                ? date('Y-m-d', strtotime((string) $account->createdAt))
                : '2000-01-01';

            DB::table('FundsAccountMonthlyTarget')->insert([
                'id' => (string) Str::uuid(),
                'fundsAccountId' => $account->id,
                'amount' => $account->monthlyAmount ?? 0,
                'effectiveDate' => $effectiveDate,
                'createdAt' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('FundsAccountMonthlyTarget');
    }
};
