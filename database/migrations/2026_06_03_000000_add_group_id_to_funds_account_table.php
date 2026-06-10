<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('FundsAccount', function (Blueprint $table): void {
            $table->foreignUuid('groupId')
                ->nullable()
                ->after('tenantId')
                ->constrained('Group')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('FundsAccount', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('groupId');
        });
    }
};
