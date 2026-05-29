<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('Type', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenantId')->constrained('Tenant')->cascadeOnDelete();
            $table->string('group');
            $table->string('type');
            $table->text('description')->nullable();
            $table->timestampTz('createdAt')->useCurrent();
            $table->timestampTz('updatedAt')->useCurrent();
            $table->unique(['tenantId', 'group', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('Type');
    }
};
