<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('PaymentBreakdown', function (Blueprint $table): void {
            $table->foreignUuid('memberId')
                ->nullable()
                ->after('paymentId')
                ->constrained('Member')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('PaymentBreakdown', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('memberId');
        });
    }
};
