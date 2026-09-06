<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Payment', function (Blueprint $table): void {
            $table->string('payorAlias')
                ->nullable()
                ->after('memberId');
        });
    }

    public function down(): void
    {
        Schema::table('Payment', function (Blueprint $table): void {
            $table->dropColumn('payorAlias');
        });
    }
};
