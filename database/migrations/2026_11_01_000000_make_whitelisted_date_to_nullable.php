<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Whitelisted', function (Blueprint $table): void {
            $table->date('dateTo')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('Whitelisted', function (Blueprint $table): void {
            $table->date('dateTo')->nullable(false)->change();
        });
    }
};
