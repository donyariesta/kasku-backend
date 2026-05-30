<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('FundsAccount', function (Blueprint $table): void {
            $table->boolean('isSystem')->default(false)->after('monthlyAmount');
        });

        Schema::table('Payment', function (Blueprint $table): void {
            $table->timestampTz('distributedAt')->nullable()->after('status');
        });

        Schema::create('FundsTransfer', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenantId')->constrained('Tenant')->cascadeOnDelete();
            $table->foreignUuid('fromFundsAccountId')->constrained('FundsAccount')->restrictOnDelete();
            $table->foreignUuid('toFundsAccountId')->constrained('FundsAccount')->restrictOnDelete();
            $table->integer('month');
            $table->integer('year');
            $table->double('amount');
            $table->timestampTz('date')->useCurrent();
            $table->string('description')->nullable();
            $table->timestampTz('createdAt')->useCurrent();
            $table->timestampTz('updatedAt')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('FundsTransfer');
        Schema::table('Payment', function (Blueprint $table): void {
            $table->dropColumn('distributedAt');
        });
        Schema::table('FundsAccount', function (Blueprint $table): void {
            $table->dropColumn('isSystem');
        });
    }
};
