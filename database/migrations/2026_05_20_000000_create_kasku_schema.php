<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('Tenant', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->timestampTz('createdAt')->useCurrent();
            $table->timestampTz('updatedAt')->useCurrent();
        });

        Schema::create('User', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('username')->unique();
            $table->string('password');
            $table->string('name')->nullable();
            $table->string('role')->default('MEMBER');
            $table->foreignUuid('tenantId')->nullable()->constrained('Tenant')->nullOnDelete();
            $table->timestampTz('createdAt')->useCurrent();
            $table->timestampTz('updatedAt')->useCurrent();
        });

        Schema::create('Group', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->foreignUuid('tenantId')->constrained('Tenant')->cascadeOnDelete();
            $table->timestampTz('createdAt')->useCurrent();
            $table->timestampTz('updatedAt')->useCurrent();
            $table->unique(['name', 'tenantId']);
        });

        Schema::create('Member', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('houseNumber');
            $table->string('phoneNumber')->nullable();
            $table->string('status')->default('active');
            $table->foreignUuid('tenantId')->constrained('Tenant')->cascadeOnDelete();
            $table->foreignUuid('userId')->nullable()->unique()->constrained('User')->nullOnDelete();
            $table->foreignUuid('groupId')->nullable()->constrained('Group')->nullOnDelete();
            $table->timestampTz('createdAt')->useCurrent();
            $table->timestampTz('updatedAt')->useCurrent();
        });

        Schema::create('Payment', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('memberId')->constrained('Member')->restrictOnDelete();
            $table->foreignUuid('tenantId')->constrained('Tenant')->cascadeOnDelete();
            $table->integer('month');
            $table->integer('year');
            $table->double('amount');
            $table->timestampTz('date')->useCurrent();
            $table->uuid('treasurerId');
            $table->string('status')->default('paid');
        });

        Schema::create('Expense', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->string('category');
            $table->text('description')->nullable();
            $table->double('amount');
            $table->timestampTz('date')->useCurrent();
            $table->foreignUuid('tenantId')->constrained('Tenant')->cascadeOnDelete();
            $table->uuid('treasurerId');
        });

        Schema::create('Setting', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenantId')->constrained('Tenant')->cascadeOnDelete();
            $table->integer('fieldId');
            $table->timestampTz('datetimeValue')->nullable();
            $table->date('dateValue')->nullable();
            $table->text('stringValue')->nullable();
            $table->boolean('booleanValue')->nullable();
            $table->bigInteger('numberValue')->nullable();
            $table->json('jsonValue')->nullable();
            $table->binary('blobValue')->nullable();
            $table->timestampTz('createdAt')->useCurrent();
            $table->timestampTz('updatedAt')->useCurrent();
            $table->unique(['fieldId', 'tenantId']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('Setting');
        Schema::dropIfExists('Expense');
        Schema::dropIfExists('Payment');
        Schema::dropIfExists('Member');
        Schema::dropIfExists('Group');
        Schema::dropIfExists('User');
        Schema::dropIfExists('Tenant');
    }
};
