<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Expense', function (Blueprint $table): void {
            $table->string('status')->nullable()->after('date');
            $table->foreignUuid('memberId')
                ->nullable()
                ->after('date')
                ->constrained('Member')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('Expense', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('memberId');
            $table->dropColumn('status');
        });
    }
};
