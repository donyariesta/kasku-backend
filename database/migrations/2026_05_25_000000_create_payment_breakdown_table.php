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
        if (!Schema::hasColumn('FundsAccount', 'isSystem')) {
            Schema::table('FundsAccount', function (Blueprint $table): void {
                $table->boolean('isSystem')->default(false)->after('monthlyAmount');
            });
        }

        Schema::create('PaymentBreakdown', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('paymentId')->constrained('Payment')->cascadeOnDelete();
            $table->double('amount');
            $table->foreignUuid('fundsAccountId')->constrained('FundsAccount')->restrictOnDelete();
            $table->integer('month');
            $table->integer('year');
            $table->text('notes')->nullable();
        });

        Schema::table('Payment', function (Blueprint $table): void {
            $table->text('notes')->nullable()->after('status');
        });

        $payments = DB::table('Payment')->get();

        foreach ($payments as $payment) {
            if (!Schema::hasColumn('Payment', 'fundsAccountId')) {
                continue;
            }

            if (!$payment->fundsAccountId) {
                continue;
            }

            DB::table('PaymentBreakdown')->insert([
                'id' => (string) Str::uuid(),
                'paymentId' => $payment->id,
                'amount' => $payment->amount,
                'fundsAccountId' => $payment->fundsAccountId,
                'month' => $payment->month,
                'year' => $payment->year,
                'notes' => null,
            ]);
        }

        Schema::table('Payment', function (Blueprint $table): void {
            if (Schema::hasColumn('Payment', 'fundsAccountId')) {
                $table->dropConstrainedForeignId('fundsAccountId');
            }
            if (Schema::hasColumn('Payment', 'month')) {
                $table->dropColumn('month');
            }
            if (Schema::hasColumn('Payment', 'year')) {
                $table->dropColumn('year');
            }
            if (Schema::hasColumn('Payment', 'distributedAt')) {
                $table->dropColumn('distributedAt');
            }
        });
    }

    public function down(): void
    {
        Schema::table('Payment', function (Blueprint $table): void {
            $table->foreignUuid('fundsAccountId')
                ->nullable()
                ->after('tenantId')
                ->constrained('FundsAccount')
                ->restrictOnDelete();
            $table->integer('month')->default(1)->after('fundsAccountId');
            $table->integer('year')->default(2026)->after('month');
            $table->timestampTz('distributedAt')->nullable()->after('status');
        });

        $breakdowns = DB::table('PaymentBreakdown')->get();
        foreach ($breakdowns as $row) {
            DB::table('Payment')
                ->where('id', $row->paymentId)
                ->update([
                    'fundsAccountId' => $row->fundsAccountId,
                    'month' => $row->month,
                    'year' => $row->year,
                ]);
        }

        Schema::table('Payment', function (Blueprint $table): void {
            $table->dropColumn('notes');
        });

        Schema::dropIfExists('PaymentBreakdown');

        if (Schema::hasColumn('FundsAccount', 'isSystem')) {
            Schema::table('FundsAccount', function (Blueprint $table): void {
                $table->dropColumn('isSystem');
            });
        }
    }
};
