<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Member', function (Blueprint $table): void {
            $table->string('memberPinHash')->nullable()->after('groupId');
        });
    }

    public function down(): void
    {
        Schema::table('Member', function (Blueprint $table): void {
            $table->dropColumn('memberPinHash');
        });
    }
};
