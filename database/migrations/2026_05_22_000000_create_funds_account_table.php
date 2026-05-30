<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('FundsAccount', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenantId')->constrained('Tenant')->cascadeOnDelete();
            $table->string('name');
            $table->boolean('active')->default(true);
            $table->double('monthlyAmount')->default(0);
            $table->timestampTz('createdAt')->useCurrent();
            $table->timestampTz('updatedAt')->useCurrent();
            $table->unique(['tenantId', 'name']);
        });

        Schema::table('Payment', function (Blueprint $table): void {
            $table->foreignUuid('fundsAccountId')
                ->nullable()
                ->after('tenantId')
                ->constrained('FundsAccount')
                ->restrictOnDelete();
        });

        Schema::table('Expense', function (Blueprint $table): void {
            $table->foreignUuid('fundsAccountId')
                ->nullable()
                ->after('tenantId')
                ->constrained('FundsAccount')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('Expense', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('fundsAccountId');
        });

        Schema::table('Payment', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('fundsAccountId');
        });

        Schema::dropIfExists('FundsAccount');
    }
};
