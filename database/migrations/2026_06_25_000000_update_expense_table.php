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
        Schema::table('Expense', function (Blueprint $table): void {
            $table->foreignUuid('typeId')
                ->nullable()
                ->after('tenantId')
                ->constrained('Type')
                ->restrictOnDelete();
        });

        $expenses = DB::table('Expense')->get();
        foreach ($expenses as $expense) {
            $type = DB::table('Type')
                ->where('type', $expense->category)
                ->first();

            if (!$type) {
                continue;
            }

            DB::table('Expense')
                ->where('id', $expense->id)
                ->update([
                    'typeId' => $type ? $type->id : null,
                ]);
        }

        Schema::table('Expense', function (Blueprint $table): void {
            if (Schema::hasColumn('Expense', 'category')) {
                $table->dropColumn('category');
            }
        });
    }

    public function down(): void
    {
        Schema::table('Expense', function (Blueprint $table): void {
            $table->string('category')->nullable()->after('tenantId');
        });

        $expenses = DB::table('Expense')->get();
        foreach ($expenses as $expense) {
            $type = DB::table('Type')
                ->where('id', $expense->typeId)
                ->first();

            if (!$type) {
                continue;
            }

            DB::table('Expense')
                ->where('id', $expense->id)
                ->update([
                    'category' => $type ? $type->type : null,
                ]);
        }

        Schema::table('Expense', function (Blueprint $table): void {
            if (Schema::hasColumn('Expense', 'typeId')) {
                $table->dropColumn('typeId');
            }
        });
    }
};
