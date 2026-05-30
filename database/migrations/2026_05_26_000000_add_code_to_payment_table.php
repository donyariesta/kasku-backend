<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Payment', function (Blueprint $table): void {
            $table->unsignedTinyInteger('code')->default(1)->after('status');
        });

        DB::table('Payment')->update(['code' => 1]);
    }

    public function down(): void
    {
        Schema::table('Payment', function (Blueprint $table): void {
            $table->dropColumn('code');
        });
    }
};
