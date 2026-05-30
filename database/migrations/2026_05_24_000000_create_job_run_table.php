<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('JobRun', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('jobKey');
            $table->string('status')->default('queued');
            $table->string('trigger')->default('manual');
            $table->foreignUuid('triggeredByUserId')->nullable()->constrained('User')->nullOnDelete();
            $table->foreignUuid('tenantId')->nullable()->constrained('Tenant')->nullOnDelete();
            $table->json('result')->nullable();
            $table->text('error')->nullable();
            $table->timestampTz('queuedAt')->nullable();
            $table->timestampTz('startedAt')->nullable();
            $table->timestampTz('finishedAt')->nullable();
            $table->timestampTz('createdAt')->useCurrent();
            $table->timestampTz('updatedAt')->useCurrent();
            $table->index(['jobKey', 'status']);
            $table->index('createdAt');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('JobRun');
    }
};
