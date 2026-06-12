<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('Whitelisted', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenantId')->constrained('Tenant')->cascadeOnDelete();
            $table->foreignUuid('memberId')->constrained('Member')->restrictOnDelete();
            $table->date('dateFrom');
            $table->date('dateTo');
            $table->foreignUuid('typeId')->constrained('Type')->restrictOnDelete();
            $table->double('allowance')->default(0);
            $table->text('notes')->nullable();
            $table->timestampTz('createdAt')->useCurrent();
            $table->timestampTz('updatedAt')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('Whitelisted');
    }
};
