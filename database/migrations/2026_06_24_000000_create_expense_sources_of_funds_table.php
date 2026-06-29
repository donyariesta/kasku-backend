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

        Schema::create('ExpenseSourceOfFunds', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('expenseId')->constrained('Expense')->cascadeOnDelete();
            $table->double('amount');
            $table->foreignUuid('fundsAccountId')->constrained('FundsAccount')->restrictOnDelete();
        });

        $expenses = DB::table('Expense')->get();
        foreach ($expenses as $expense) {
            if (!Schema::hasColumn('Expense', 'fundsAccountId')) {
                continue;
            }

            if (!$expense->fundsAccountId) {
                continue;
            }

            DB::table('ExpenseSourceOfFunds')->insert([
                'id' => (string) Str::uuid(),
                'expenseId' => $expense->id,
                'amount' => $expense->amount,
                'fundsAccountId' => $expense->fundsAccountId,
            ]);
        }

        Schema::table('Expense', function (Blueprint $table): void {
            if (Schema::hasColumn('Expense', 'fundsAccountId')) {
                $table->dropConstrainedForeignId('fundsAccountId');
            }
        });
    }

    public function down(): void
    {
        Schema::table('Expense', function (Blueprint $table): void {
            $table->foreignUuid('fundsAccountId')
                ->nullable()
                ->after('tenantId')
                ->constrained('FundsAccount')
                ->restrictOnDelete();
        });

        $sourceOfFunds = DB::table('ExpenseSourceOfFunds')->get();
        foreach ($sourceOfFunds as $row) {
            DB::table('Expense')
                ->where('id', $row->expenseId)
                ->update([
                    'fundsAccountId' => $row->fundsAccountId,
                ]);
        }

        Schema::dropIfExists('ExpenseSourceOfFunds');
    }
};
